"""Sklearn Bloom trainer — plan Sec 5. Train in Python, infer in PHP, JSON is the contract.

Parity with BloomClassifierService.php:54-84 (plan Sec 3):
  tokenizer: lower(), re.sub(r'[^a-z\\s]',' ',...), split \\s+, drop len<=2
  ngrams: unigrams + bigrams joined with underscore (custom analyzer, NOT ngram_range)
  features: raw counts; labels 0-5 fixed order; verify clf.classes_ == [0..5].

Usage:
  python scripts/bloom/train_bloom_sklearn.py --input data/bloom/samples.csv \\
      --out storage/app/ml/bloom_model.json --min-df 5 --C 1.0 --max-iter 1000 \\
      --seed 42 --class-weight balanced --ph-weight 2
  python scripts/bloom/train_bloom_sklearn.py --grid-search --cv 5 [other flags]
"""
from __future__ import annotations

import argparse
import csv
import datetime
import json
import re
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
LEVELS = ["Remembering", "Understanding", "Applying", "Analyzing", "Evaluating", "Creating"]

_WORD_RE = re.compile(r"[^a-z\s]")
_WS_RE = re.compile(r"\s+")


def tokenize(text: str) -> list[str]:
    text = text.lower()
    text = _WORD_RE.sub(" ", text)
    return [t for t in _WS_RE.split(text.strip()) if len(t) > 2]


def analyzer(text: str) -> list[str]:
    toks = tokenize(text)
    return toks + [toks[i] + "_" + toks[i + 1] for i in range(len(toks) - 1)]


def load_samples(path: Path, ph_weight: int, anchor_weight: int = 1):
    import pandas as pd

    df = pd.read_csv(path)
    texts = df["text"].astype(str).tolist()
    labels = df["label"].astype(int).tolist()
    sources = df["source"].astype(str).tolist() if "source" in df.columns else [""] * len(df)
    # Physical upweight: duplicate ph_k12 / anchor rows (plan Sec 4.1).
    if ph_weight > 1 or anchor_weight > 1:
        extra_t, extra_y = [], []
        for t, y, s in zip(texts, labels, sources):
            if s == "ph_k12":
                extra_t.extend([t] * (ph_weight - 1))
                extra_y.extend([y] * (ph_weight - 1))
            elif s == "anchor":
                extra_t.extend([t] * (anchor_weight - 1))
                extra_y.extend([y] * (anchor_weight - 1))
        texts += extra_t
        labels += extra_y
        print(f"Upweight: ph_k12 x{ph_weight}, anchor x{anchor_weight} "
              f"(+{len(extra_t)} duplicated rows)", flush=True)
    return texts, labels


def build_vectorizer(min_df: int):
    from sklearn.feature_extraction.text import CountVectorizer

    return CountVectorizer(analyzer=analyzer, min_df=min_df)


def build_model(C: float, max_iter: int, class_weight):
    from sklearn.linear_model import LogisticRegression

    try:
        return LogisticRegression(
            multi_class="multinomial", solver="lbfgs", C=C,
            max_iter=max_iter, class_weight=class_weight, random_state=42,
        )
    except TypeError:  # sklearn>=1.8 removed multi_class (lbfgs is always multinomial)
        return LogisticRegression(
            solver="lbfgs", C=C, max_iter=max_iter,
            class_weight=class_weight, random_state=42,
        )


def eval_split(name: str, y_true, y_pred):
    from sklearn.metrics import (accuracy_score, classification_report,
                                 confusion_matrix, f1_score)

    print(f"\n# Train-test split of {name}", flush=True)
    print(f"# Model: Logistic Regression (multinomial, lbfgs)", flush=True)
    print(classification_report(y_true, y_pred, target_names=LEVELS, digits=2), flush=True)
    cm = confusion_matrix(y_true, y_pred, labels=[0, 1, 2, 3, 4, 5])
    print("# Confusion matrix (rows=actual, cols=predicted)", flush=True)
    print(cm, flush=True)
    rep = classification_report(y_true, y_pred, target_names=LEVELS, output_dict=True)
    return {
        "accuracy": accuracy_score(y_true, y_pred),
        "macro_f1": f1_score(y_true, y_pred, average="macro"),
        "weighted_f1": f1_score(y_true, y_pred, average="weighted"),
        "report": rep,
        "confusion_matrix": cm.tolist(),
    }


def save_artifacts(split_key: str, metrics: dict, outdir: Path):
    levels = LEVELS
    rep = metrics["report"]
    # CSV + TeX classification report
    csv_p = outdir / f"classification_report_{split_key}.csv"
    with csv_p.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["level", "precision", "recall", "f1", "support"])
        for lv in levels:
            r = rep[lv]
            w.writerow([lv, round(r["precision"], 4), round(r["recall"], 4),
                        round(r["f1-score"], 4), int(r["support"])])
    lines = ["\\begin{tabular}{lcccc}", "\\hline", "Level & Precision & Recall & F1 & Support \\\\",
             "\\hline"]
    for lv in levels:
        r = rep[lv]
        lines.append(f"{lv} & {r['precision']:.2f} & {r['recall']:.2f} & "
                     f"{r['f1-score']:.2f} & {int(r['support'])} \\\\")
    lines += ["\\hline", "\\end{tabular}"]
    (outdir / f"classification_report_{split_key}.tex").write_text("\n".join(lines), encoding="utf-8")
    # Confusion matrix CSV + PNG heatmap
    cm = metrics["confusion_matrix"]
    with (outdir / f"confusion_matrix_{split_key}.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["actual/predicted"] + levels)
        for lv, row in zip(levels, cm):
            w.writerow([lv] + row)
    try:
        import matplotlib

        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
        import seaborn as sns

        fig, ax = plt.subplots(figsize=(8, 6))
        sns.heatmap(cm, annot=True, fmt="d", cmap="Blues",
                    xticklabels=levels, yticklabels=levels, ax=ax)
        ax.set_xlabel("Predicted")
        ax.set_ylabel("Actual")
        ax.set_title(f"Confusion matrix ({split_key})")
        fig.tight_layout()
        fig.savefig(outdir / f"confusion_matrix_{split_key}.png", dpi=120)
        plt.close(fig)
    except Exception as e:
        print(f"WARN: heatmap failed ({e})", flush=True)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", default=str(REPO / "data/bloom/samples.csv"))
    ap.add_argument("--out", default=str(REPO / "storage/app/ml/bloom_model.json"))
    ap.add_argument("--min-df", type=int, default=5)
    ap.add_argument("--C", type=float, default=1.0)
    ap.add_argument("--max-iter", type=int, default=1000)
    ap.add_argument("--val-split", type=float, default=0.2)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--class-weight", default="balanced",
                    choices=["balanced", "none"])
    ap.add_argument("--ph-weight", type=int, default=2)
    ap.add_argument("--anchor-weight", type=int, default=1)
    ap.add_argument("--grid-search", action="store_true")
    ap.add_argument("--cv", type=int, default=5)
    ap.add_argument("--no-export", action="store_true",
                    help="Evaluate only, do not overwrite model JSON.")
    args = ap.parse_args()

    cw = None if args.class_weight == "none" else "balanced"
    texts, labels = load_samples(Path(args.input), args.ph_weight, args.anchor_weight)
    print(f"Samples: {len(texts)} (after upweight)", flush=True)

    if args.grid_search:
        from sklearn.model_selection import StratifiedKFold, GridSearchCV

        vec = build_vectorizer(args.min_df)
        # Grid over C/min_df/class_weight would need re-vectorizing per min_df;
        # do manual loop so min_df is honored exactly.
        best = None
        results = []
        for min_df in (2, 5):
            v = build_vectorizer(min_df)
            X = v.fit_transform(texts)
            for C in (0.5, 1.0, 2.0, 4.0):
                for cw_opt in ("balanced", None):
                    m = build_model(C, args.max_iter, cw_opt)
                    cv = StratifiedKFold(n_splits=args.cv, shuffle=True, random_state=args.seed)
                    from sklearn.model_selection import cross_val_score

                    scores = cross_val_score(m, X, labels, cv=cv, scoring="f1_macro")
                    row = {"min_df": min_df, "C": C, "class_weight": cw_opt,
                           "mean": float(scores.mean()), "std": float(scores.std())}
                    results.append(row)
                    print(f"grid min_df={min_df} C={C} cw={cw_opt}: "
                          f"f1_macro {row['mean']:.4f} +/- {row['std']:.4f}", flush=True)
                    if best is None or row["mean"] > best["mean"]:
                        best = row
        print(f"\nBest: {best}", flush=True)
        args.min_df, args.C = best["min_df"], best["C"]
        cw = best["class_weight"]
        print(f"Continuing with min_df={args.min_df} C={args.C} cw={cw}", flush=True)

    from sklearn.model_selection import StratifiedKFold, cross_val_score, train_test_split

    outdir = Path(args.input).parent
    metrics: dict = {"C": args.C, "min_df": args.min_df, "seed": args.seed,
                     "class_weight": cw, "ph_weight": args.ph_weight,
                     "anchor_weight": args.anchor_weight, "splits": {}}

    vec = build_vectorizer(args.min_df)
    X_all = vec.fit_transform(texts)
    vocab = vec.get_feature_names_out().tolist()
    metrics["vocab_size"] = len(vocab)
    print(f"Vocab size: {len(vocab)} (min_df={args.min_df})", flush=True)

    import numpy as np

    y_all = np.array(labels)
    # Both holdout splits (plan Sec 4.3/5.1): 80/20 and 70/30.
    for split, test_size, key in ((0.2, 0.2, "80_20"), (0.3, 0.3, "70_30")):
        Xtr, Xte, ytr, yte = train_test_split(
            X_all, y_all, test_size=test_size, random_state=args.seed, stratify=y_all)
        clf = build_model(args.C, args.max_iter, cw)
        clf.fit(Xtr, ytr)
        assert list(clf.classes_) == [0, 1, 2, 3, 4, 5], f"classes_={clf.classes_}"
        train_acc = clf.score(Xtr, ytr)
        m = eval_split(key.replace("_", "/"), yte, clf.predict(Xte))
        m["train_accuracy"] = float(train_acc)
        gap = train_acc - m["accuracy"]
        print(f"# train_acc={train_acc:.3f} val_acc={m['accuracy']:.3f} gap={gap:.3f}", flush=True)
        if gap > 0.15:
            print("WARN: train - val > 0.15, overfit guard tripped (plan Sec 5).", flush=True)
        metrics["splits"][key] = m
        save_artifacts(key, m, outdir)

    # 5-fold CV macro F1
    cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=args.seed)
    scores = cross_val_score(build_model(args.C, args.max_iter, cw), X_all, y_all,
                             cv=cv, scoring="f1_macro")
    print(f"\n# 5-Fold CV (macro F1)\n" + "  ".join(f"Fold {i+1}: {s:.2f}" for i, s in enumerate(scores)),
          flush=True)
    print(f"Mean: {scores.mean():.4f} +/- {scores.std():.4f}", flush=True)
    metrics["cv"] = {"mean": float(scores.mean()), "std": float(scores.std()),
                     "folds": [float(s) for s in scores]}

    run_tag = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    (outdir / f"metrics_{run_tag}.json").write_text(json.dumps(metrics, indent=2), encoding="utf-8")
    print(f"\nMetrics -> {outdir / f'metrics_{run_tag}.json'}", flush=True)

    if not args.no_export:
        final = build_model(args.C, args.max_iter, cw)
        final.fit(X_all, y_all)
        assert list(final.classes_) == [0, 1, 2, 3, 4, 5]
        model = {
            "vocab": vocab,
            "weights": final.coef_.tolist(),
            "bias": final.intercept_.tolist(),
            "levels": LEVELS,
            "trained_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "sample_count": len(texts),
        }
        outp = Path(args.out)
        if outp.exists():
            bak = outp.with_suffix(outp.suffix + f".bak.{run_tag}")
            outp.rename(bak)
            print(f"Backup old model -> {bak}", flush=True)
        outp.parent.mkdir(parents=True, exist_ok=True)
        outp.write_text(json.dumps(model), encoding="utf-8")
        print(f"Model ({len(texts)} samples, vocab {len(vocab)}) -> {outp}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
