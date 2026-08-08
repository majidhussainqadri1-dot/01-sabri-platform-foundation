#!/usr/bin/env python3
from pathlib import Path
import hashlib

root = Path(__file__).resolve().parents[1]
path = root / 'includes/class-spf-platform-engineering.php'
s = path.read_text(encoding='utf-8')
old = "\t\tif ( in_array( $module_key, $required, true ) || in_array( $module_key, $optional, true ) ) {\n\t\t\treturn new WP_Error( 'spf_scaffold_self_dependency', __( 'Golden-path scaffolding cannot generate a module that depends on itself.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );\n\t\t}"
new = "\t\t$dependency_keys = array();\n\t\tforeach ( array_merge( $required, $optional ) as $dependency ) {\n\t\t\t$dependency_key = is_array( $dependency ) ? sanitize_key( $dependency['module_key'] ?? '' ) : sanitize_key( $dependency );\n\t\t\tif ( '' !== $dependency_key ) {\n\t\t\t\t$dependency_keys[] = $dependency_key;\n\t\t\t}\n\t\t}\n\t\tif ( in_array( $module_key, $dependency_keys, true ) ) {\n\t\t\treturn new WP_Error( 'spf_scaffold_self_dependency', __( 'Golden-path scaffolding cannot generate a module that depends on itself.', 'sabri-platform-foundation' ), array( 'status'=>400 ) );\n\t\t}"
if s.count(old) != 1:
    raise SystemExit(f'Expected one Round 10 guard, found {s.count(old)}')
path.write_text(s.replace(old, new, 1), encoding='utf-8', newline='\n')

manifest = root / 'SOURCE-CHECKSUMS.sha256'
lines=[]
for line in manifest.read_text(encoding='utf-8').splitlines():
    digest, rel = line.split('  ', 1)
    if rel == 'includes/class-spf-platform-engineering.php':
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
    lines.append(f'{digest}  {rel}')
manifest.write_text('\n'.join(lines)+'\n', encoding='utf-8', newline='\n')
print('Round 10 runtime self-dependency guard corrected.')
