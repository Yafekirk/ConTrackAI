"""Train a clause-type classifier (TF-IDF + logistic regression)."""
from __future__ import annotations

from pathlib import Path

import joblib
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import classification_report
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline

from training_data import generate_dataset

ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "clause_model.joblib"


def build_pipeline() -> Pipeline:
    return Pipeline(
        [
            (
                "tfidf",
                TfidfVectorizer(
                    lowercase=True,
                    ngram_range=(1, 2),
                    min_df=1,
                    max_df=0.95,
                    sublinear_tf=True,
                    strip_accents="unicode",
                ),
            ),
            (
                "clf",
                LogisticRegression(
                    max_iter=400,
                    class_weight="balanced",
                    C=2.0,
                    solver="lbfgs",
                ),
            ),
        ]
    )


def train(save: bool = True) -> Pipeline:
    rows = generate_dataset()
    texts = [t for t, _ in rows]
    labels = [y for _, y in rows]
    x_train, x_test, y_train, y_test = train_test_split(
        texts,
        labels,
        test_size=0.2,
        random_state=42,
        stratify=labels,
    )
    pipe = build_pipeline()
    pipe.fit(x_train, y_train)
    pred = pipe.predict(x_test)
    report = classification_report(y_test, pred, zero_division=0)
    print(f"Training rows: {len(rows)}")
    print(report)
    if save:
        MODEL_PATH.parent.mkdir(parents=True, exist_ok=True)
        joblib.dump(pipe, MODEL_PATH)
        metrics_path = MODEL_PATH.parent / "metrics.txt"
        metrics_path.write_text(report, encoding="utf-8")
        print(f"Saved {MODEL_PATH}")
        print(f"Saved {metrics_path}")
    return pipe


def load_or_train() -> Pipeline:
    if MODEL_PATH.is_file():
        return joblib.load(MODEL_PATH)
    return train(save=True)


if __name__ == "__main__":
    train()
