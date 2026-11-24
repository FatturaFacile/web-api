#!/usr/bin/env python3
"""Merge the clover reports of the coverage shards and check the thresholds.

A line is covered if it is covered in at least one shard.

Usage: merge-coverage.py [--min N] [--module-min NAME=N ...] [--output clover.xml] clover-1.xml clover-2.xml ...
"""
import argparse
import re
import sys
import time
import xml.etree.ElementTree as ET
from collections import defaultdict


def read(path, files):
    """Add the executable lines of a clover report to files: {file: {line: hits}}."""
    for f in ET.parse(path).getroot().iter("file"):
        lines = files[f.get("name")]
        for ln in f.iter("line"):
            if ln.get("type") != "stmt":
                continue
            num = int(ln.get("num"))
            lines[num] = max(lines.get(num, 0), int(ln.get("count", 0)))


def module_of(path):
    m = re.search(r"/modules/([^/]+)/", path)
    if m:
        return "modules/" + m.group(1)
    return "app" if "/app/" in path else "other"


def pct(covered, total):
    return 100.0 * covered / total if total else 100.0


def write_clover(files, output):
    now = str(int(time.time()))
    root = ET.Element("coverage", generated=now)
    project = ET.SubElement(root, "project", timestamp=now)
    tot = cov = 0
    for name in sorted(files):
        lines = files[name]
        f = ET.SubElement(project, "file", name=name)
        for num in sorted(lines):
            ET.SubElement(f, "line", num=str(num), type="stmt", count=str(lines[num]))
        c = sum(1 for h in lines.values() if h > 0)
        ET.SubElement(f, "metrics", statements=str(len(lines)), coveredstatements=str(c))
        tot += len(lines)
        cov += c
    ET.SubElement(project, "metrics", files=str(len(files)), statements=str(tot), coveredstatements=str(cov))
    ET.indent(root)
    ET.ElementTree(root).write(output, encoding="UTF-8", xml_declaration=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("reports", nargs="+")
    ap.add_argument("--min", type=float, default=0, help="global minimum line coverage, percent")
    ap.add_argument("--module-min", action="append", default=[], metavar="NAME=N", help="minimum for one module")
    ap.add_argument("--output", default="clover.xml")
    args = ap.parse_args()

    files = defaultdict(dict)
    for r in args.reports:
        read(r, files)

    mods = defaultdict(lambda: [0, 0])
    for name, lines in files.items():
        m = mods[module_of(name)]
        m[0] += sum(1 for h in lines.values() if h > 0)
        m[1] += len(lines)
    covered = sum(m[0] for m in mods.values())
    total = sum(m[1] for m in mods.values())

    for name in sorted(mods):
        print(f"{name:40s} {pct(*mods[name]):6.2f}%  ({mods[name][0]}/{mods[name][1]})")
    total_pct = pct(covered, total)
    print(f"{'TOTAL':40s} {total_pct:6.2f}%  ({covered}/{total})")
    write_clover(files, args.output)

    failed = False
    if total_pct < args.min:
        print(f"FAIL: total coverage {total_pct:.2f}% is below {args.min}%")
        failed = True
    for spec in args.module_min:
        name, _, value = spec.partition("=")
        got = pct(*mods[name]) if name in mods else 0.0
        if got < float(value):
            print(f"FAIL: {name} coverage {got:.2f}% is below {value}%")
            failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
