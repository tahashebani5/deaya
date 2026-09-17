"""Turn «ارقام الزباين.xlsx» into database/seeders/data/customers.php.

Run once, by hand, from the repository root, and commit the result — the same arrangement
`data/libya_delivery_locations.php` uses. The seeder must not read a spreadsheet at runtime:
production has no copy of it, and a seeder that depends on a file nobody deployed is a seeder
that works on one laptop.

    python3 backend/database/seeders/data/extract_customers.py "Info/ارقام الزباين (1).xlsx"

**No openpyxl.** An .xlsx is a zip of XML and the two sheets this repository reads are flat
tables, so the twenty lines below replace a dependency that has to be installed on whichever
machine happens to re-run this. The sheet is read once every few months; the reader is not
where cleverness pays.

What the sheet holds, and what becomes of it
--------------------------------------------
`CODE` is the business's own customer number — A1, A2, … — printed on bags and said down the
phone since long before this system. It is carried through verbatim and becomes both the
customer's `code` and their primary key, which is what keeps `code` = 'A'.`id` true for the
imported book as well as for everyone created since. See AllocateCustomerIdentifier.

`اسم النشاط` and `نوع النشاط` are the shop's trading name and its trade. They live on a
*shop*, not on the customer, so every row that names either produces one.

`Place` and `نوع النشاط` are left as the sheet's own words. They resolve to `cities`/`regions`
and `business_fields` rows in the seeder, because those ids only exist in a seeded database.

Rows that do not survive
------------------------
Two rules, and both are recorded in the file's own header so the count can be audited:

  * **No telephone number, no import.** `customers.phone` is NOT NULL and unique, and a
    customer with no number is one nobody can ring — 24 of these rows are a code and nothing
    else. They are written to `customers-without-a-phone.xlsx` instead of being dropped, so
    the business can fill the numbers in and re-run this.
  * **One number, one customer.** The sheet has the same number under two codes 22 times —
    «سناء احمد / رونق» is A51 and again A165, the same shop typed twice months apart. Those
    rows merge into a single customer under the first code, and each row's shop is kept, so a
    genuine two-shop owner keeps both.
"""
import collections
import pathlib
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET
import zipfile

NS = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
OUT = pathlib.Path('backend/database/seeders/data/customers.php')
LEFTOVERS = pathlib.Path('backend/database/seeders/data/customers-without-a-phone.xlsx')


def sheet_rows(path):
    """Every row of the first worksheet, as a list of column-letter -> text dicts."""
    book = zipfile.ZipFile(path)

    shared = []
    if 'xl/sharedStrings.xml' in book.namelist():
        for si in ET.fromstring(book.read('xl/sharedStrings.xml')):
            shared.append(''.join(t.text or '' for t in si.iter(NS + 't')))

    def text(cell):
        kind = cell.get('t')
        if kind == 's':
            return shared[int(cell.find(NS + 'v').text)]
        if kind == 'inlineStr':
            return ''.join(t.text or '' for t in cell.iter(NS + 't'))
        value = cell.find(NS + 'v')
        return value.text if value is not None else ''

    sheet = ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
    for row in sheet.iter(NS + 'row'):
        cells = {}
        for cell in row.findall(NS + 'c'):
            cells[''.join(ch for ch in cell.get('r') if ch.isalpha())] = text(cell)
        yield int(row.get('r')), cells


def clean(value):
    """A trimmed string, or None. «**» is the sheet's own way of writing «لا أعرف»."""
    if value is None:
        return None
    value = unicodedata.normalize('NFKC', str(value)).strip()
    # Excel stores a phone typed as 0912… as the number 912…, so a value can arrive as
    # '926667646.0'. Strip that before anything else looks at it.
    if value.endswith('.0'):
        value = value[:-2]
    return None if value in ('', '*', '**', '***') else value


def phone(value):
    """Libyan mobile, normalised to the 10-digit 09XXXXXXXX form the app writes elsewhere.

    A number that will not normalise is still returned — 9 of them are a digit short or carry
    a foreign country code, and a wrong number on file is a correction, while a dropped
    customer is a customer nobody can find. Only the *absence* of a number stops an import.
    """
    value = clean(value)
    if not value:
        return None
    digits = re.sub(r'\D', '', value)
    if digits.startswith('00218'):
        digits = digits[5:]
    elif digits.startswith('218'):
        digits = digits[3:]
    if len(digits) == 9 and digits[0] == '9':      # 926667646 -> 0926667646
        digits = '0' + digits
    return digits or None


def php(value):
    if value is None:
        return 'null'
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def fold(value):
    """A spelling-insensitive key. Arabic is typed أ/ا, ة/ه and ى/ي interchangeably here."""
    if not value:
        return ''
    value = re.sub(r'[\u064B-\u0652\u0640]', '', value.strip().lower())
    value = value.translate(str.maketrans({'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ى': 'ي', 'ة': 'ه'}))
    return re.sub(r'\s+', ' ', value)


def better(current, candidate, vague=()):
    """Whichever of the two says more. Null says nothing; «أخرى» barely says more."""
    if candidate is None:
        return current
    if current is None:
        return candidate
    if fold(current) in vague and fold(candidate) not in vague:
        return candidate
    return current


def shops_for(group, name):
    """The shops behind a customer's rows — one per trading name, not one per row.

    Three things happen here, and each of them is a row the sheet holds twice:

      * **Same shop, two spellings.** «رونق» in مصراتة and «رونق» in مصراته are one shop; the
        key is folded, so they collapse and the entry keeps whichever spelling came first.
      * **A trade against a shop that already has one.** The later row for «دانتيلات» says
        «أخرى» where the earlier says «ملابس». The specific answer wins — «أخرى» is what
        somebody picks when the list has no better row, not a correction.
      * **A row with a trade but no trading name.** Half the book is a person with one shop,
        entered once with its name and again with only «ساده» beside it. That second row is
        not a second shop: it fills in the trade or the place the named one is missing. Only
        when *no* row names a shop does the customer's own name become one, because the trade
        has to hang off something and a customer with none would lose it altogether.
    """
    named, loose = [], []
    for entry in group:
        if entry['shop']:
            named.append(entry)
        elif entry['field'] or entry['place']:
            loose.append(entry)

    shops = []
    for entry in named:
        key = fold(entry['shop'])
        existing = next((s for s in shops if s['key'] == key), None)
        if existing is None:
            shops.append({'key': key, 'name': entry['shop'],
                          'field': entry['field'], 'place': entry['place']})
            continue
        existing['field'] = better(existing['field'], entry['field'], vague=('اخري',))
        existing['place'] = better(existing['place'], entry['place'], vague=('استلام من المكتب',))

    if not shops and loose:
        shops.append({'key': fold(name), 'name': name, 'field': None, 'place': None})

    # Into the first named shop: a customer with two shops and a loose row gives no way to
    # tell which of them the row is about, and the first is the one the book recorded first.
    for entry in loose:
        shops[0]['field'] = better(shops[0]['field'], entry['field'], vague=('اخري',))
        shops[0]['place'] = better(shops[0]['place'], entry['place'], vague=('استلام من المكتب',))

    return [(s['name'], s['field'], s['place']) for s in shops]


def main(source):
    rows, blank = [], 0
    for number, cells in list(sheet_rows(source))[1:]:      # [1:] skips the header row
        entry = {
            'row': number,
            'code': clean(cells.get('A')),
            'name': clean(cells.get('B')),
            'phone': phone(cells.get('C')),
            'shop': clean(cells.get('D')),
            'field': clean(cells.get('E')),
            'note': clean(cells.get('F')),
            'place': clean(cells.get('G')),
        }
        if not any(v for k, v in entry.items() if k != 'row'):
            blank += 1
            continue
        rows.append(entry)

    # ── one number, one customer ───────────────────────────────────────────────────────────
    # Insertion-ordered, so the customer keeps the *first* code the sheet gave the number —
    # the older one, which is the one already written on somebody's bag.
    by_phone, without = collections.OrderedDict(), []
    for entry in rows:
        if entry['phone']:
            by_phone.setdefault(entry['phone'], []).append(entry)
        else:
            without.append(entry)
    merged = list(by_phone.values())

    # ── codes ──────────────────────────────────────────────────────────────────────────────
    # A1..A667 as written. A code the sheet left as «**», or gave to two different people, is
    # reassigned above the highest one in use rather than renumbering the book.
    taken, homeless = set(), []
    for group in merged:
        number = next((int(m.group(1)) for e in group
                       if (m := re.fullmatch(r'A(\d+)', e['code'] or ''))), None)
        if number is None or number in taken:
            homeless.append(group)
            continue
        taken.add(number)
        group[0]['number'] = number

    following = max(taken) + 1
    for group in homeless:
        while following in taken:
            following += 1
        taken.add(following)
        group[0]['number'] = following

    customers = sorted(merged, key=lambda g: g[0]['number'])

    # ── the file ───────────────────────────────────────────────────────────────────────────
    absorbed = sum(len(g) - 1 for g in merged)
    lines = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '/*',
        ' * The customer book, transcribed from «ارقام الزباين.xlsx» by extract_customers.py.',
        ' *',
        ' * Generated and committed, exactly as database/seeders/data/libya_delivery_locations.php',
        ' * is: a seeder that reads a spreadsheet at runtime is a seeder that only runs where',
        ' * somebody happened to leave the spreadsheet.',
        ' *',
        f' * {len(rows) + blank} rows in, {len(customers)} customers out:',
        f' *   - {blank} blank row(s) ignored',
        f' *   - {absorbed} row(s) merged into an earlier customer sharing their telephone number',
        f' *   - {len(without)} row(s) held back for having no number at all — see',
        f' *     {LEFTOVERS.name}, which this script writes beside this file',
        ' *',
        " * `id` is the number in `code`, so the customer the business calls A51 is row 51 here",
        ' * and id 51 in the database. `place` and `field` are the sheet\'s own words, resolved',
        ' * against cities/regions and business_fields by the seeder — those ids only exist in a',
        ' * seeded database.',
        ' */',
        '',
        'return [',
    ]

    for group in customers:
        first = group[0]
        code = 'A' + str(first['number'])
        # The person, when the sheet names one. A shop with no owner written beside it is
        # looked up by its trading name, and a code is the last resort — `name` is NOT NULL,
        # and a blank one is a row nobody can read.
        name = first['name'] or next((e['shop'] for e in group if e['shop']), None) or code
        note = next((e['note'] for e in group if e['note']), None)

        shops = shops_for(group, name)

        lines.append(f"    ['code' => {php(code)}, 'name' => {php(name)}, "
                     f"'phone' => {php(first['phone'])}, 'note' => {php(note)}, 'shops' => [")
        for shop_name, field, place in shops:
            lines.append(f"        ['name' => {php(shop_name)}, 'field' => {php(field)}, "
                         f"'place' => {php(place)}],")
        lines.append('    ]],')

    lines += ['];', '']
    OUT.write_text('\n'.join(lines), encoding='utf-8')

    write_leftovers(without)

    print(f'rows in            : {len(rows) + blank}')
    print(f'customers written  : {len(customers)}')
    print(f'  blank rows       : {blank}')
    print(f'  merged on phone  : {absorbed}')
    print(f'  held back (no phone): {len(without)} -> {LEFTOVERS}')
    print(f'codes              : A1..A{max(taken)}, {len(homeless)} reassigned')
    print(f'\nwrote {OUT} ({OUT.stat().st_size // 1024} KB)')


def write_leftovers(entries):
    """The rows with no telephone number, as a workbook the business can fill in and hand back.

    Written by hand for the same reason the reader above is: this file exists so somebody can
    type 39 numbers into it, and that is not worth a dependency.
    """
    headers = ['CODE', 'Name', 'Phone Number', 'اسم النشاط', 'نوع النشاط', 'ملاحظة ثابته', 'Place']
    keys = ['code', 'name', 'phone', 'shop', 'field', 'note', 'place']
    strings = []
    index = {}

    def shared(text):
        if text not in index:
            index[text] = len(strings)
            strings.append(text)
        return index[text]

    def escape(text):
        return (text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;'))

    body = []
    for r, row in enumerate([headers] + [[e[k] or '' for k in keys] for e in entries], start=1):
        cells = ''.join(
            f'<c r="{chr(64 + c)}{r}" t="s"><v>{shared(str(v))}</v></c>'
            for c, v in enumerate(row, start=1))
        body.append(f'<row r="{r}">{cells}</row>')

    sheet = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             f'<sheetData>{"".join(body)}</sheetData></worksheet>')
    table = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
             f'count="{len(strings)}" uniqueCount="{len(strings)}">'
             + ''.join(f'<si><t xml:space="preserve">{escape(s)}</t></si>' for s in strings)
             + '</sst>')

    with zipfile.ZipFile(LEFTOVERS, 'w', zipfile.ZIP_DEFLATED) as book:
        book.writestr('[Content_Types].xml',
                      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                      '<Default Extension="xml" ContentType="application/xml"/>'
                      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                      '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                      '</Types>')
        book.writestr('_rels/.rels',
                      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                      '</Relationships>')
        book.writestr('xl/workbook.xml',
                      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                      '<sheets><sheet name="بلا رقم هاتف" sheetId="1" r:id="rId1"/></sheets></workbook>')
        book.writestr('xl/_rels/workbook.xml.rels',
                      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                      '</Relationships>')
        book.writestr('xl/worksheets/sheet1.xml', sheet)
        book.writestr('xl/sharedStrings.xml', table)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else 'Info/ارقام الزباين (1).xlsx')
