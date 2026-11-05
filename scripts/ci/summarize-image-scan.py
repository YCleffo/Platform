"""Expose all Trivy findings and fail on fixable HIGH/CRITICAL vulnerabilities."""

import argparse
import json
import os
import sys
from collections import Counter
from pathlib import Path
from typing import NotRequired, TypedDict, cast


class Finding(TypedDict):
    VulnerabilityID: NotRequired[str]
    PkgName: NotRequired[str]
    InstalledVersion: NotRequired[str]
    Severity: str
    FixedVersion: NotRequired[str]


class ScanResult(TypedDict):
    Vulnerabilities: NotRequired[list[Finding] | None]


class ScanReport(TypedDict):
    Results: list[ScanResult]

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("report", type=Path)
parser.add_argument(
    "--plane",
    choices=["Core", "Commercial", "PostgreSQL", "Redis", "Mailpit"],
    required=True,
)
parser.add_argument(
    "--fail-on-fixable",
    action="store_true",
    help="Exit non-zero when a HIGH or CRITICAL finding has a released fix.",
)
args = parser.parse_args()
report = cast(ScanReport, json.loads(args.report.read_text(encoding="utf-8")))
if "Results" not in report:
    raise ValueError("Missing Trivy Results; refusing an empty success summary")
findings = [item for result in report["Results"] for item in (result.get("Vulnerabilities") or [])]
total = Counter(item["Severity"] for item in findings)
fixable = Counter(item["Severity"] for item in findings if item.get("FixedVersion"))
blocking = [
    item
    for item in findings
    if item["Severity"] in {"CRITICAL", "HIGH"} and item.get("FixedVersion")
]
lines = [f"### {args.plane} image vulnerabilities", "", "| Severity | All | Fix available | No fix available |",
         "| --- | ---: | ---: | ---: |"]
for severity in ["CRITICAL", "HIGH"]:
    lines.append(f"| {severity} | {total[severity]} | {fixable[severity]} | {total[severity] - fixable[severity]} |")
lines.extend(["", "CI blocks HIGH/CRITICAL findings with a released fix. The full inventory includes unfixed findings.",
              "A passing fixable-vulnerability gate does not establish production readiness.", ""])
if blocking:
    lines.extend(
        [
            f"The following {len(blocking)} fixable HIGH/CRITICAL finding(s) block the gate:",
            "",
            "| CVE | Package | Installed | Fixed | Severity |",
            "| --- | --- | --- | --- | --- |",
        ]
    )
    for item in blocking[:50]:
        lines.append(
            "| {id} | {package} | {installed} | {fixed} | {severity} |".format(
                id=item.get("VulnerabilityID", "unknown"),
                package=item.get("PkgName", "unknown"),
                installed=item.get("InstalledVersion", "unknown"),
                fixed=item.get("FixedVersion", "unknown"),
                severity=item["Severity"],
            )
        )
    if len(blocking) > 50:
        lines.append(f"| ... | {len(blocking) - 50} more finding(s) | | | |")
    lines.append("")
summary = "\n".join(lines)
print(summary)
if destination := os.environ.get("GITHUB_STEP_SUMMARY"):
    with Path(destination).open("a", encoding="utf-8") as output:
        output.write(summary)
if unfixed_critical := total["CRITICAL"] - fixable["CRITICAL"]:
    print(f"::warning::{args.plane} image has {unfixed_critical} CRITICAL finding(s) without a released fix; see the full inventory.")
if args.fail_on_fixable and blocking:
    print(
        f"::error::{args.plane} image has {len(blocking)} HIGH/CRITICAL vulnerability finding(s) with a released fix.",
        file=sys.stderr,
    )
    raise SystemExit(1)
