"""Expose all Trivy findings alongside the separate fixable-vulnerability gate."""

import argparse
from collections import Counter
import json
import os
from pathlib import Path
from typing import NotRequired, TypedDict, cast


class Finding(TypedDict):
    Severity: str
    FixedVersion: NotRequired[str]


class ScanResult(TypedDict):
    Vulnerabilities: NotRequired[list[Finding] | None]


class ScanReport(TypedDict):
    Results: list[ScanResult]

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("report", type=Path)
parser.add_argument("--plane", choices=["Core", "Commercial"], required=True)
args = parser.parse_args()
report = cast(ScanReport, json.loads(args.report.read_text(encoding="utf-8")))
if "Results" not in report:
    raise ValueError("Missing Trivy Results; refusing an empty success summary")
findings = [item for result in report["Results"] for item in (result.get("Vulnerabilities") or [])]
total = Counter(item["Severity"] for item in findings)
fixable = Counter(item["Severity"] for item in findings if item.get("FixedVersion"))
lines = [f"### {args.plane} image vulnerabilities", "", "| Severity | All | Fix available | No fix available |",
         "| --- | ---: | ---: | ---: |"]
for severity in ["CRITICAL", "HIGH"]:
    lines.append(f"| {severity} | {total[severity]} | {fixable[severity]} | {total[severity] - fixable[severity]} |")
lines.extend(["", "CI blocks HIGH/CRITICAL findings with a released fix. The full inventory includes unfixed findings.",
              "A passing fixable-vulnerability gate does not establish production readiness.", ""])
summary = "\n".join(lines)
print(summary)
if destination := os.environ.get("GITHUB_STEP_SUMMARY"):
    with Path(destination).open("a", encoding="utf-8") as output:
        output.write(summary)
if unfixed_critical := total["CRITICAL"] - fixable["CRITICAL"]:
    print(f"::warning::{args.plane} image has {unfixed_critical} CRITICAL finding(s) without a released fix; see the full inventory.")
