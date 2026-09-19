"""Build data/bloom/samples.csv from raw sources (plan Sec 4.2).

Sources (per user decisions 2026-09-19):
  - Kaggle vijaydevane 8,767 questions -> rewritten to objectives via template,
    label BT1..BT6 -> 0..5. (user: Rewrite/include)
  - jsano/blooms_taxonomy PH K-12 (35 topics x 6 = 210 questions) -> source=ph_k12,
    upweighted 2x at train time via source column. (user: 2x)
  - app/Services/Bloom/BloomTrainingData.php ICT/Tagalog anchor (~420 rows)
    -> source=anchor. Keeps OSI/malware/mag-aaral weights.
Excluded (documented deviations from plan Table 4.1):
  - EDM 21k (Li et al.): paper collected via private scraper, no public release
    found; SteveLEEEEE handle unresolvable. Skipped with warning.
  - frankwong2001: only ssf-* synthetic sets on HF, no blooms set found. Skipped
    (jsano PH part still included).
  - mouryat9/CogBench silver 26k: user chose Exclude from core (gold 739 kept
    for eval only, never in samples.csv).
  - itsskofficial/falcon-7b (3,902): label collapse (Evaluation 19, Synthesis 2)
    would wreck BT5/BT6 recall. Excluded, documented.
  - evaluate_holdout_v2.py 60 sentences: never in training output (plan Sec 4.2).
    Exact normalized-text matches are dropped.

Output: CSV with columns text,label,source. Dedupe on normalized text.
"""
from __future__ import annotations

import argparse
import csv
import re
import sys
from collections import Counter
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DEFAULT_KAGGLE = (
    Path.home()
    / ".cache/kagglehub/datasets/vijaydevane/blooms-taxonomy-dataset/versions/5/blooms_taxonomy_dataset.csv"
)
PHP_DATA = REPO / "app/Services/Bloom/BloomTrainingData.php"
HOLDOUT = REPO / "evaluate_holdout_v2.py"

BT_MAP = {"BT1": 0, "BT2": 1, "BT3": 2, "BT4": 3, "BT5": 4, "BT6": 5}
JSANO_MAP = {"remember": 0, "understand": 1, "apply": 2, "analyze": 3, "evaluate": 4, "create": 5}
PHP_CONST = {
    "REMEMBERING": 0, "UNDERSTANDING": 1, "APPLYING": 2,
    "ANALYZING": 3, "EVALUATING": 4, "CREATING": 5,
}
LEVELS = ["Remembering", "Understanding", "Applying", "Analyzing", "Evaluating", "Creating"]


def norm_ws(s: str) -> str:
    return re.sub(r"\s+", " ", s.strip())


def rewrite_question(q: str) -> str:
    """Plan Sec 4.1 template: 'Students will be able to ' + lcfirst(rstrip(question,'?')) + '.'"""
    q = norm_ws(q).rstrip("?").strip()
    if not q:
        return ""
    q = q[0].lower() + q[1:] if len(q) > 1 else q.lower()
    return f"Students will be able to {q}."


def load_holdout_texts() -> set[str]:
    texts: set[str] = set()
    if HOLDOUT.exists():
        src = HOLDOUT.read_text(encoding="utf-8")
        for m in re.finditer(r'\(\s*"((?:[^"\\]|\\.)+)"\s*,\s*"(?:Remembering|Understanding|Applying|Analyzing|Evaluating|Creating)"', src):
            texts.add(norm_ws(m.group(1)).lower())
    return texts


def load_kaggle(path: Path) -> list[tuple[str, int, str]]:
    import pandas as pd

    df = pd.read_csv(path)
    rows = []
    for _, r in df.iterrows():
        q, cat = str(r["Questions"]), str(r["Category"]).strip()
        if cat not in BT_MAP or not q.strip():
            continue
        rows.append((rewrite_question(q), BT_MAP[cat], "kaggle"))
    return rows


def load_jsano() -> list[tuple[str, int, str]]:
    from datasets import load_dataset

    ds = load_dataset("jsano/blooms_taxonomy")
    examples = ds["train"][0]["examples"]
    rows = []
    for ex in examples:
        qs = ex.get("questions", {})
        for key, label in JSANO_MAP.items():
            item = qs.get(key)
            if isinstance(item, dict):
                q = norm_ws(str(item.get("question", "")))
                if q:
                    rows.append((q, label, "ph_k12"))
    return rows


def load_php_anchor() -> list[tuple[str, int, str]]:
    src = PHP_DATA.read_text(encoding="utf-8")
    pat = re.compile(r'\["((?:[^"\\]|\\.)+)",\s*self::(REMEMBERING|UNDERSTANDING|APPLYING|ANALYZING|EVALUATING|CREATING)\]')
    rows = []
    for m in pat.finditer(src):
        text = m.group(1).encode().decode("unicode_escape", errors="ignore")
        rows.append((norm_ws(text), PHP_CONST[m.group(2)], "anchor"))
    return rows


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--kaggle", default=str(DEFAULT_KAGGLE))
    ap.add_argument("--out", default=str(REPO / "data/bloom/samples.csv"))
    ap.add_argument("--seed", type=int, default=42)
    args = ap.parse_args()

    holdout = load_holdout_texts()
    print(f"Holdout v2 exclusion set: {len(holdout)} sentences", flush=True)

    all_rows: list[tuple[str, int, str]] = []
    per_source: Counter = Counter()

    kaggle_path = Path(args.kaggle)
    if kaggle_path.exists():
        k = load_kaggle(kaggle_path)
        all_rows += k
        per_source["kaggle"] = len(k)
        print(f"kaggle rewritten: {len(k)}", flush=True)
    else:
        print(f"WARN: kaggle csv not found at {kaggle_path}, skipping", flush=True)

    try:
        j = load_jsano()
        all_rows += j
        per_source["ph_k12"] = len(j)
        print(f"jsano ph_k12: {len(j)}", flush=True)
    except Exception as e:  # offline training machine etc.
        print(f"WARN: jsano load failed ({e}), skipping", flush=True)

    if PHP_DATA.exists():
        a = load_php_anchor()
        all_rows += a
        per_source["anchor"] = len(a)
        print(f"php anchor: {len(a)}", flush=True)

    print("NOTE: EDM 21k skipped (no public release); frankwong2001 skipped (no blooms set); "
          "CogBench silver excluded per user; itsskofficial excluded (label collapse).", flush=True)

    # Dedupe on normalized lowercase text; drop holdout overlap. Keep first occurrence.
    seen: set[str] = set()
    deduped: list[tuple[str, int, str]] = []
    dropped_holdout = dropped_dupe = 0
    for text, label, source in all_rows:
        key = norm_ws(text).lower()
        if key in holdout:
            dropped_holdout += 1
            continue
        if key in seen:
            dropped_dupe += 1
            continue
        seen.add(key)
        deduped.append((norm_ws(text), label, source))

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["text", "label", "source"])
        w.writerows(deduped)

    print(f"\nWrote {len(deduped)} rows -> {out}", flush=True)
    print(f"Dropped: {dropped_dupe} exact-dupes, {dropped_holdout} holdout-overlaps", flush=True)
    lvl = Counter(l for _, l, _ in deduped)
    print("\nStratified counts per level:", flush=True)
    for i, name in enumerate(LEVELS):
        print(f"  {name:15} {lvl.get(i, 0):5}", flush=True)
    print("\nPer-source input counts:", flush=True)
    for s, c in per_source.items():
        print(f"  {s:10} {c:5}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
