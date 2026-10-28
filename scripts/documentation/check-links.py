"""Check local Markdown file/image targets in project documentation."""
from pathlib import Path
import re
import urllib.parse

ROOT=Path(__file__).resolve().parents[2]
files=[ROOT/'README.md',*sorted((ROOT/'docs').rglob('*.md'))]
errors=[]
count=0
for file in files:
    for number,line in enumerate(file.read_text(encoding='utf-8').splitlines(),1):
        for target in re.findall(r'\[[^\]]*\]\(([^)]+)\)',line):
            target=target.strip().split(' "',1)[0].strip('<>')
            if not target or target.startswith('#') or re.match(r'^[a-zA-Z][a-zA-Z0-9+.-]*:',target):
                continue
            target=urllib.parse.unquote(target.split('#',1)[0])
            if not target:
                continue
            count+=1
            if not (file.parent/target).exists():
                errors.append(f'{file.relative_to(ROOT)}:{number}: {target}')
if errors:
    print('\n'.join(errors))
    raise SystemExit(1)
print(f'PASS: {count} local links in {len(files)} Markdown documents')
