import json, re, sys

LEVELS = ['Remembering', 'Understanding', 'Applying', 'Analyzing', 'Evaluating', 'Creating']

# Held-out set v2 — written fresh, deliberately NOT reused from the v1
# holdout (some v1 sentences were folded into training to fix specific
# confusions, so reusing them here would leak test data into training).
HOLDOUT_V2 = [
    ("Students can name the different types of storage devices used in computers.", "Remembering"),
    ("Learners will recite the steps involved in the water cycle.", "Remembering"),
    ("Students should identify the different generations of programming languages.", "Remembering"),
    ("Learners will list down the parts of the executive branch of government.", "Remembering"),
    ("The students must recall the formula for calculating a triangle's area.", "Remembering"),
    ("Learners can state the definition of an algorithm.", "Remembering"),
    ("Students will name the common file extensions used for image files.", "Remembering"),
    ("The students should identify the basic parts of a spreadsheet application.", "Remembering"),
    ("Learners will recall the different types of computer viruses by name.", "Remembering"),
    ("Students can list the members of ASEAN.", "Remembering"),

    ("Students will explain how a firewall protects a computer network.", "Understanding"),
    ("Learners can summarize the plot of a short story read in class.", "Understanding"),
    ("The students should discuss why version control is important in software projects.", "Understanding"),
    ("Learners will describe how photosynthesis converts sunlight into energy.", "Understanding"),
    ("Students can explain the significance of the EDSA People Power Revolution.", "Understanding"),
    ("The students will interpret the meaning of a given pie chart.", "Understanding"),
    ("Learners can explain how a compiler translates source code into machine code.", "Understanding"),
    ("Students will discuss the role of a project manager in a software team.", "Understanding"),
    ("The students should describe how an API allows two systems to communicate.", "Understanding"),
    ("Learners can explain in their own words how DNS resolves a domain name.", "Understanding"),

    ("Students will create a working budget spreadsheet using basic formulas.", "Applying"),
    ("Learners can set up a Git repository and make an initial commit.", "Applying"),
    ("The students should write a simple program that calculates a student's average grade.", "Applying"),
    ("Learners will demonstrate how to connect a printer to a shared network.", "Applying"),
    ("Students can use a debugger to step through a program and find where it fails.", "Applying"),
    ("The students must apply proper subject-verb agreement rules in a written paragraph.", "Applying"),
    ("Learners will use a spreadsheet formula to compute the total sales for the month.", "Applying"),
    ("Students should demonstrate how to reset a forgotten account password securely.", "Applying"),
    ("The students will apply basic first aid procedures in a simulated scenario.", "Applying"),
    ("Learners can configure user permissions on a shared folder.", "Applying"),

    ("Students will identify the root cause of a recurring server downtime issue.", "Analyzing"),
    ("Learners can distinguish between a phishing email and a legitimate one.", "Analyzing"),
    ("The students should compare two job scheduling algorithms in terms of fairness.", "Analyzing"),
    ("Learners will deconstruct a persuasive essay to identify its supporting arguments.", "Analyzing"),
    ("Students can differentiate between compile-time errors and runtime errors.", "Analyzing"),
    ("The students must analyze survey results to identify recurring complaints.", "Analyzing"),
    ("Learners will examine two competing app designs to identify usability issues.", "Analyzing"),
    ("Students should categorize a set of network incidents by severity level.", "Analyzing"),
    ("The students will compare the memory usage of two different data structures.", "Analyzing"),
    ("Learners can identify inconsistencies between a system's design and its documentation.", "Analyzing"),

    ("Students will rate the quality of a proposed database backup strategy.", "Evaluating"),
    ("Learners can decide, with reasons, which of two coding standards better fits a team.", "Evaluating"),
    ("The students should evaluate the fairness of a proposed grading rubric.", "Evaluating"),
    ("Learners will critique the security posture of a sample login system.", "Evaluating"),
    ("Students can defend their choice of framework for a given web project.", "Evaluating"),
    ("The students must weigh whether a system upgrade is worth its downtime cost.", "Evaluating"),
    ("Learners will assess whether a piece of source code follows best practices.", "Evaluating"),
    ("Students should evaluate a research paper's methodology for validity.", "Evaluating"),
    ("The students will judge which of three vendors offers the best value for an IT contract.", "Evaluating"),
    ("Learners can determine whether a proposed policy adequately addresses data privacy concerns.", "Evaluating"),

    ("Students will invent a new algorithm to optimize delivery routes for a logistics app.", "Creating"),
    ("Learners can compose an original short story incorporating a given theme.", "Creating"),
    ("The students should design a mobile app wireframe for a school attendance system.", "Creating"),
    ("Learners will construct an entity-relationship diagram for an online bookstore.", "Creating"),
    ("Students can produce an original research proposal addressing a local ICT problem.", "Creating"),
    ("The students must design a chatbot flow to handle common student inquiries.", "Creating"),
    ("Learners will assemble a complete deployment pipeline for a web application.", "Creating"),
    ("Students should formulate a new grading system algorithm for a school registrar.", "Creating"),
    ("The students will develop an original lesson plan integrating gamification.", "Creating"),
    ("Learners can construct a disaster preparedness plan for a barangay ICT center.", "Creating"),
]

def tokenize(text):
    text = text.lower()
    text = re.sub(r'[^a-z\s]', ' ', text)
    tokens = re.split(r'\s+', text.strip())
    return [t for t in tokens if len(t) > 2]

def ngrams(text):
    tokens = tokenize(text)
    grams = list(tokens)
    for i in range(len(tokens) - 1):
        grams.append(tokens[i] + '_' + tokens[i + 1])
    return grams

def classify(text, model):
    vocab_index = {w: i for i, w in enumerate(model['vocab'])}
    vocab_size = len(model['vocab'])
    vec = [0.0] * vocab_size
    for tok in ngrams(text):
        if tok in vocab_index:
            vec[vocab_index[tok]] += 1.0
    num_classes = len(model['levels'])
    z = []
    for c in range(num_classes):
        dot = model['bias'][c]
        for f, val in enumerate(vec):
            if val != 0.0:
                dot += model['weights'][c][f] * val
        z.append(dot)
    best = z.index(max(z))
    return model['levels'][best]

def main():
    model_path = sys.argv[1] if len(sys.argv) > 1 else 'bloom_model.json'
    with open(model_path) as f:
        model = json.load(f)

    confusion = {a: {p: 0 for p in LEVELS} for a in LEVELS}
    correct = 0
    errors = []

    for text, actual in HOLDOUT_V2:
        predicted = classify(text, model)
        confusion[actual][predicted] += 1
        if predicted == actual:
            correct += 1
        else:
            errors.append((text, actual, predicted))

    total = len(HOLDOUT_V2)
    print("=== HELD-OUT V2 EVALUATION (fully fresh, never in training) ===")
    print(f"Total examples: {total}")
    print(f"Overall accuracy: {round(correct/total*100, 1)}%\n")

    print("=== CONFUSION MATRIX (rows = actual, cols = predicted) ===")
    print(f"{'':15}" + "".join(f"{l[:7]:8}" for l in LEVELS))
    for a in LEVELS:
        print(f"{a:15}" + "".join(f"{confusion[a][p]:<8}" for p in LEVELS))

    print("\n=== PER-CLASS METRICS ===")
    print(f"{'Level':15} {'Precision':10} {'Recall':10} {'F1':10} {'Support':8}")
    macro_p = macro_r = macro_f1 = 0
    for l in LEVELS:
        tp = confusion[l][l]
        fp = sum(confusion[o][l] for o in LEVELS if o != l)
        fn = sum(confusion[l][o] for o in LEVELS if o != l)
        precision = tp / (tp + fp) if (tp + fp) > 0 else 0
        recall = tp / (tp + fn) if (tp + fn) > 0 else 0
        f1 = 2 * precision * recall / (precision + recall) if (precision + recall) > 0 else 0
        support = tp + fn
        print(f"{l:15} {round(precision*100,1):>8}%  {round(recall*100,1):>7}%  {round(f1*100,1):>7}%  {support:>6}")
        macro_p += precision; macro_r += recall; macro_f1 += f1
    n = len(LEVELS)
    print(f"{'MACRO AVG':15} {round(macro_p/n*100,1):>8}%  {round(macro_r/n*100,1):>7}%  {round(macro_f1/n*100,1):>7}%")

    print(f"\n=== MISCLASSIFIED EXAMPLES ({len(errors)}) ===")
    for text, actual, predicted in errors:
        print(f"- [{actual} -> predicted {predicted}] \"{text}\"")

if __name__ == '__main__':
    main()
