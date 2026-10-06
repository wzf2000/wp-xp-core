"""Build immutable browser resources, then publish the manifest."""

from pathlib import Path
import hashlib
import json

assets = Path(__file__).resolve().parent / "assets"
mapping = {}
for key, name in [("js", "app.js"), ("css", "app.css")]:
    content = (assets / name).read_bytes()
    path = Path(name)
    target = path.stem + "-" + hashlib.sha256(content).hexdigest()[:12] + path.suffix
    (assets / target).write_bytes(content)
    mapping[key] = target
(assets / "assets.json").write_text(json.dumps(mapping, indent=2) + "\n")
