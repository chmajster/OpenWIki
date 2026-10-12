"""Render real API benchmark measurements; supports shell UTF-8 and PowerShell UTF-16 output."""
import json
import statistics
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding='utf-8')


def read(path):
    data = Path(path).read_bytes()
    return json.loads(data.decode('utf-16' if data.startswith((b'\xff\xfe', b'\xfe\xff')) else 'utf-8-sig'))


before, after = map(read, sys.argv[1:3])
assert before['pages'] == after['pages'] == 10000
assert before['engine'] == after['engine'] and before['php'] == after['php']
print(f"PHP {after['php']}; {after['engine']}; 10 000 stron. Mediana pięciu wywołań kontrolera API z rzeczywistym tokenem i bazą, włącznie z pierwszym przebiegiem; czas transportu HTTP jest wyłączony. Pamięć to maksymalny przyrost szczytu względem początku wywołania. Liczba SQL pochodzi z sesyjnego licznika `Questions`, z wyłączeniem samych pomiarów licznika.\n")
print('| Zapytanie | Czas przed → po (ms) | SQL przed → po | Pamięć przed → po (MiB) | Total przed → po |')
print('|---|---:|---:|---:|---:|')
for query in dict.fromkeys(row['query'] for row in before['runs']):
    old = [row for row in before['runs'] if row['query'] == query]
    new = [row for row in after['runs'] if row['query'] == query]
    assert len(old) == len(new) == 5
    assert all(row['status'] == 200 for row in old + new)
    values = []
    for rows in [old, new]:
        values.append((statistics.median(row['ms'] for row in rows), statistics.median(row['queries'] for row in rows), max(row['peak_bytes'] for row in rows) / 1048576, rows[0]['total']))
    a, b = values
    print(f'| `{query}` | {a[0]:.2f} → {b[0]:.2f} | {a[1]:g} → {b[1]:g} | {a[2]:.2f} → {b[2]:.2f} | {a[3]} → {b[3]} |')
