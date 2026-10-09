"""Generate a draw.io ERD whose connectors stay in the gutters between tables."""
import html
from pathlib import Path

HEADER = 32
ROW_H = 22
OUT = Path(__file__).with_name("NAAC-ERD.drawio")

# Column pitch leaves a gutter between every pair of tables.
XA, WA = 120, 300
XB, WB = 620, 320
XC, WC = 1140, 360
XD, WD = 1720, 400
Y0, Y1, Y2 = 200, 760, 1520

GROUPS = {
    "people": ("#1D4ED8", "#1E3A8A"),
    "catalog": ("#047857", "#064E3B"),
    "sales": ("#C2410C", "#9A3412"),
    "config": ("#7E22CE", "#581C87"),
    "logistics": ("#475569", "#334155"),
}


def height(n):
    return HEADER + n * ROW_H


def row_cy(top, index):
    return top + HEADER + index * ROW_H + ROW_H / 2


ENTITIES = {
    "branches": {
        "title": "branches",
        "group": "people",
        "x": XA, "y": Y0, "w": WA,
        "cols": [
            ("PK", "id", "bigint"),
            ("", "name", "varchar"),
            ("", "location", "varchar"),
            ("", "manager", "varchar, null"),
            ("FK", "manager_user_id", "bigint, null"),
            ("", "status", "varchar"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "employees": {
        "title": "employees",
        "group": "people",
        "x": XB, "y": Y0, "w": WB,
        "cols": [
            ("PK", "id", "bigint"),
            ("FK", "branch_id", "bigint, null"),
            ("", "first_name", "varchar, null"),
            ("", "last_name", "varchar, null"),
            ("UK", "contact_number", "varchar(20)"),
            ("UK", "contact_email", "varchar"),
            ("", "address", "varchar, null"),
            ("", "profile_picture", "varchar, null"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "users": {
        "title": "users",
        "group": "people",
        "x": XC, "y": Y0, "w": WC,
        "cols": [
            ("PK", "id", "bigint"),
            ("FK", "employee_id", "bigint"),
            ("", "user_name", "varchar, null"),
            ("", "name", "varchar, null"),
            ("UK", "email", "varchar"),
            ("", "email_verified_at", "timestamp, null"),
            ("", "password", "varchar"),
            ("", "role", "varchar"),
            ("", "is_active", "boolean"),
            ("", "is_online", "boolean"),
            ("", "remember_token", "varchar, null"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "settings": {
        "title": "settings",
        "group": "config",
        "x": XD, "y": Y0, "w": 340,
        "cols": [
            ("PK", "id", "bigint"),
            ("", "company_name", "varchar"),
            ("", "support_email", "varchar, null"),
            ("", "timezone", "varchar"),
            ("", "currency", "varchar"),
            ("", "low_stock_threshold", "int"),
            ("", "default_vat_rate", "decimal(5,2)"),
            ("", "discount_password_hash", "varchar, null"),
            ("", "replacement_window_days", "int"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "products": {
        "title": "products",
        "group": "catalog",
        "x": XA, "y": Y1, "w": WA,
        "cols": [
            ("PK", "id", "bigint"),
            ("", "name", "varchar"),
            ("", "category", "varchar, null"),
            ("", "company_name", "varchar, null"),
            ("", "unit", "varchar(50)"),
            ("", "price", "decimal(12,2)"),
            ("", "description", "text, null"),
            ("", "image", "varchar, null"),
            ("", "is_vatable", "boolean"),
            ("", "vat_rate", "decimal(5,2)"),
            ("", "retail_enabled", "boolean"),
            ("", "retail_unit", "varchar(20)"),
            ("", "retail_qty_per_unit", "int, null"),
            ("", "retail_price", "decimal(12,2), null"),
            ("", "retail_allowed_loss", "decimal(12,4)"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "inventories": {
        "title": "inventories",
        "group": "catalog",
        "x": XB, "y": Y1, "w": WB,
        "cols": [
            ("PK", "id", "bigint"),
            ("FK", "branch_id", "bigint, null"),
            ("UK", "batch_number", "varchar(40), null"),
            ("FK", "product_id", "bigint"),
            ("", "quantity", "int"),
            ("", "retail_remainder", "decimal(12,4)"),
            ("", "status", "varchar"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "sales": {
        "title": "sales",
        "group": "sales",
        "x": XC, "y": Y1, "w": WC,
        "cols": [
            ("PK", "id", "bigint"),
            ("", "sale_number", "varchar(32), null"),
            ("UK", "idempotency_key", "varchar(100), null"),
            ("FK", "product_id", "bigint"),
            ("FK", "processed_by_user_id", "bigint, null"),
            ("", "unit_type", "varchar(20), null"),
            ("", "quantity", "decimal(12,4)"),
            ("", "quantity_label", "varchar(20), null"),
            ("", "vat_rate", "decimal(5,2)"),
            ("", "vat_amount", "decimal(12,2)"),
            ("", "discount_percent", "decimal(5,2)"),
            ("", "discount_amount", "decimal(12,2)"),
            ("", "total_price", "decimal(12,2)"),
            ("", "payment_method", "varchar(20), null"),
            ("", "borrower_name", "varchar(150), null"),
            ("", "due_date", "date, null"),
            ("", "paid_at", "timestamp, null"),
            ("FK", "replaces_sale_id", "bigint, null"),
            ("FK", "replaced_by_sale_id", "bigint, null"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "product_replacements": {
        "title": "product_replacements",
        "group": "sales",
        "x": XD, "y": Y1, "w": WD,
        "cols": [
            ("PK", "id", "bigint"),
            ("UK", "replacement_number", "varchar(32)"),
            ("", "sale_number", "varchar(32)"),
            ("FK", "original_sale_id", "bigint"),
            ("FK", "new_sale_id", "bigint"),
            ("FK", "original_product_id", "bigint"),
            ("FK", "new_product_id", "bigint"),
            ("", "original_quantity", "decimal(12,4)"),
            ("", "new_quantity", "decimal(12,4)"),
            ("", "original_unit_type", "varchar(20), null"),
            ("", "new_unit_type", "varchar(20), null"),
            ("", "original_line_total", "decimal(12,2)"),
            ("", "new_line_total", "decimal(12,2)"),
            ("", "already_paid", "decimal(12,2)"),
            ("", "additional_payment", "decimal(12,2)"),
            ("", "payment_method", "varchar(20), null"),
            ("FK", "processed_by_user_id", "bigint, null"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "truckings": {
        "title": "truckings",
        "group": "logistics",
        "x": XA, "y": Y2, "w": WA,
        "cols": [
            ("PK", "id", "bigint"),
            ("", "driver_name", "varchar"),
            ("", "delivery_address", "varchar"),
            ("", "status", "varchar"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "inventory_revenue_logs": {
        "title": "inventory_revenue_logs",
        "group": "catalog",
        "x": XB, "y": Y2, "w": WB,
        "cols": [
            ("PK", "id", "bigint"),
            ("FK", "inventory_id", "bigint, null"),
            ("FK", "branch_id", "bigint, null"),
            ("FK", "product_id", "bigint, null"),
            ("", "batch_number", "varchar(40), null"),
            ("", "quantity", "int"),
            ("", "price", "decimal(12,2)"),
            ("", "expected_revenue", "decimal(12,2)"),
            ("", "action", "varchar(20)"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "daily_sales_reports": {
        "title": "daily_sales_reports",
        "group": "sales",
        "x": XC, "y": Y2, "w": WC,
        "cols": [
            ("PK", "id", "bigint"),
            ("UK", "report_number", "varchar(32)"),
            ("", "report_date", "date"),
            ("FK", "branch_id", "bigint"),
            ("FK", "submitted_by_user_id", "bigint"),
            ("", "cashier_name", "varchar, null"),
            ("", "branch_name", "varchar, null"),
            ("", "receipt_count", "int"),
            ("", "line_count", "int"),
            ("", "total_sales", "decimal(12,2)"),
            ("", "total_vat", "decimal(12,2)"),
            ("", "total_discount", "decimal(12,2)"),
            ("", "replacement_extra", "decimal(12,2)"),
            ("", "cash_counted", "decimal(12,2), null"),
            ("", "notes", "varchar(500), null"),
            ("", "payment_breakdown", "json, null"),
            ("", "items", "json, null"),
            ("", "submitted_at", "timestamp, null"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
    "discount_options": {
        "title": "discount_options",
        "group": "config",
        "x": XD, "y": Y2, "w": 340,
        "cols": [
            ("PK", "id", "bigint"),
            ("UK", "percent", "decimal(5,2)"),
            ("", "is_active", "boolean"),
            ("", "created_at", "timestamp"),
            ("", "updated_at", "timestamp"),
        ],
    },
}

for ent in ENTITIES.values():
    ent["h"] = height(len(ent["cols"]))
    ent["right"] = ent["x"] + ent["w"]
    ent["bottom"] = ent["y"] + ent["h"]


def anchor(entity, index, side):
    ent = ENTITIES[entity]
    y = row_cy(ent["y"], index)
    x = ent["x"] if side == "left" else ent["right"]
    return (x, y)


def rects():
    return [
        (name, e["x"], e["y"], e["right"], e["bottom"])
        for name, e in ENTITIES.items()
    ]


def segment_hits(x1, y1, x2, y2, ignore):
    hits = []
    if x1 != x2 and y1 != y2:
        hits.append("diagonal")
        return hits
    for name, x, y, r, b in rects():
        if name in ignore:
            continue
        # Shrink by 1px so a connector that only touches a border is allowed.
        x, y, r, b = x + 1, y + 1, r - 1, b - 1
        if x1 == x2:
            yy1, yy2 = sorted((y1, y2))
            if x < x1 < r and yy2 > y and yy1 < b:
                hits.append(name)
        else:
            xx1, xx2 = sorted((x1, x2))
            if y < y1 < b and xx2 > x and xx1 < r:
                hits.append(name)
    return hits


# Each relationship has its own stroke color and an orthogonal gutter path.
# Points are absolute page coordinates, including the row anchors.
RELATIONS = [
    {
        "id": "e_emp_branch",
        "label": "employees.branch_id → branches.id",
        "color": "#E11D48",
        "source": ("employees", 1, "left"),
        "target": ("branches", 0, "right"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(490, None), (490, "ty")],
    },
    {
        "id": "e_user_emp",
        "label": "users.employee_id → employees.id",
        "color": "#2563EB",
        "source": ("users", 1, "left"),
        "target": ("employees", 0, "right"),
        "start": "ERmandOne",
        "end": "ERzeroToOne",
        "via": [(960, None), (960, "ty")],
    },
    {
        "id": "e_branch_mgr",
        "label": "branches.manager_user_id → users.id",
        "color": "#7C3AED",
        "source": ("branches", 4, "right"),
        "target": ("users", 0, "left"),
        "start": "ERzeroToOne",
        "end": "ERzeroToOne",
        "via": [(450, None), (450, 80), (1000, 80), (1000, "ty")],
    },
    {
        "id": "e_inv_branch",
        "label": "inventories.branch_id → branches.id",
        "color": "#059669",
        "source": ("inventories", 1, "left"),
        "target": ("branches", 0, "left"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(420, None), (420, 560), (40, 560), (40, "ty")],
    },
    {
        "id": "e_inv_product",
        "label": "inventories.product_id → products.id",
        "color": "#D97706",
        "source": ("inventories", 3, "left"),
        "target": ("products", 0, "right"),
        "start": "ERzeroToMany",
        "end": "ERmandOne",
        "via": [(530, None), (530, "ty")],
    },
    {
        "id": "e_sale_product",
        "label": "sales.product_id → products.id",
        "color": "#DC2626",
        "source": ("sales", 3, "left"),
        "target": ("products", 0, "left"),
        "start": "ERzeroToMany",
        "end": "ERmandOne",
        "via": [(1010, None), (1010, 600), (70, 600), (70, "ty")],
    },
    {
        "id": "e_sale_user",
        "label": "sales.processed_by_user_id → users.id",
        "color": "#0891B2",
        "source": ("sales", 4, "left"),
        "target": ("users", 0, "left"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(1040, None), (1040, "ty")],
    },
    {
        "id": "e_sale_replaces",
        "label": "sales.replaces_sale_id → sales.id",
        "color": "#9333EA",
        "source": ("sales", 17, "right"),
        "target": ("sales", 0, "right"),
        "start": "ERzeroToOne",
        "end": "ERzeroToOne",
        "via": [(1460, None), (1460, "ty")],
    },
    {
        "id": "e_sale_replaced",
        "label": "sales.replaced_by_sale_id → sales.id",
        "color": "#DB2777",
        "source": ("sales", 18, "right"),
        "target": ("sales", 0, "right"),
        "start": "ERzeroToOne",
        "end": "ERzeroToOne",
        "via": [(1510, None), (1510, "ty")],
    },
    {
        "id": "e_rep_osale",
        "label": "product_replacements.original_sale_id → sales.id",
        "color": "#B45309",
        "source": ("product_replacements", 3, "left"),
        "target": ("sales", 0, "right"),
        "start": "ERmandOne",
        "end": "ERzeroToOne",
        "via": [(1560, None), (1560, "ty")],
    },
    {
        "id": "e_rep_nsale",
        "label": "product_replacements.new_sale_id → sales.id",
        "color": "#EA580C",
        "source": ("product_replacements", 4, "left"),
        "target": ("sales", 0, "right"),
        "start": "ERmandOne",
        "end": "ERzeroToOne",
        "via": [(1630, None), (1630, 1280), (2140, 1280), (2140, 140), (1500, 140), (1500, "ty")],
    },
    {
        "id": "e_rep_oprod",
        "label": "product_replacements.original_product_id → products.id",
        "color": "#65A30D",
        "source": ("product_replacements", 5, "right"),
        "target": ("products", 0, "right"),
        "start": "ERmandOne",
        "end": "ERzeroToOne",
        "via": [(2160, None), (2160, 1320), (500, 1320), (500, "ty")],
    },
    {
        "id": "e_rep_nprod",
        "label": "product_replacements.new_product_id → products.id",
        "color": "#0D9488",
        "source": ("product_replacements", 6, "right"),
        "target": ("products", 0, "left"),
        "start": "ERmandOne",
        "end": "ERzeroToOne",
        "via": [(2200, None), (2200, 1360), (100, 1360), (100, "ty")],
    },
    {
        "id": "e_rep_user",
        "label": "product_replacements.processed_by_user_id → users.id",
        "color": "#4F46E5",
        "source": ("product_replacements", 16, "right"),
        "target": ("users", 0, "right"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(2080, None), (2080, 110), (1390, 110), (1390, "ty")],
    },
    {
        "id": "e_rpt_branch",
        "label": "daily_sales_reports.branch_id → branches.id",
        "color": "#BE185D",
        "source": ("daily_sales_reports", 3, "left"),
        "target": ("branches", 0, "left"),
        "start": "ERoneToMany",
        "end": "ERmandOne",
        "via": [(980, None), (980, 1440), (20, 1440), (20, "ty")],
    },
    {
        "id": "e_rpt_user",
        "label": "daily_sales_reports.submitted_by_user_id → users.id",
        "color": "#0369A1",
        "source": ("daily_sales_reports", 4, "right"),
        "target": ("users", 0, "right"),
        "start": "ERoneToMany",
        "end": "ERmandOne",
        "via": [(1660, None), (1660, 1440), (2120, 1440), (2120, 50), (1480, 50), (1480, "ty")],
    },
    {
        "id": "e_log_inv",
        "label": "inventory_revenue_logs.inventory_id → inventories.id",
        "color": "#16A34A",
        "source": ("inventory_revenue_logs", 1, "left"),
        "target": ("inventories", 0, "left"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(460, None), (460, "ty")],
    },
    {
        "id": "e_log_branch",
        "label": "inventory_revenue_logs.branch_id → branches.id",
        "color": "#A16207",
        "source": ("inventory_revenue_logs", 2, "left"),
        "target": ("branches", 0, "left"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(540, None), (540, 1480), (60, 1480), (60, "ty")],
    },
    {
        "id": "e_log_product",
        "label": "inventory_revenue_logs.product_id → products.id",
        "color": "#0F766E",
        "source": ("inventory_revenue_logs", 3, "right"),
        "target": ("products", 0, "right"),
        "start": "ERzeroToMany",
        "end": "ERzeroToOne",
        "via": [(1080, None), (1080, 1400), (540, 1400), (540, "ty")],
    },
]


def resolve_path(rel):
    sx, sy = anchor(*rel["source"])
    tx, ty = anchor(*rel["target"])
    pts = [(sx, sy)]
    prev_x, prev_y = sx, sy
    for item in rel["via"]:
        x, y = item
        if x is None:
            x = prev_x
        if y is None:
            y = prev_y
        if y == "ty":
            y = ty
        if x == "tx":
            x = tx
        pts.append((x, y))
        prev_x, prev_y = x, y
    pts.append((tx, ty))
    # Drop consecutive duplicates.
    cleaned = [pts[0]]
    for p in pts[1:]:
        if p != cleaned[-1]:
            cleaned.append(p)
    return cleaned


def validate(rel, pts):
    ignore = {rel["source"][0], rel["target"][0]}
    problems = []
    for (x1, y1), (x2, y2) in zip(pts, pts[1:]):
        hits = segment_hits(x1, y1, x2, y2, ignore)
        if hits:
            problems.append(((x1, y1), (x2, y2), hits))
    return problems


def esc(text):
    return html.escape(text, quote=True)


cells = []
cell_id = 2


def add(xml):
    cells.append(xml)


add(
    """<mxCell id="title" value="NAAC entity relationship diagram" style="text;html=1;fontSize=22;fontStyle=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontColor=#0F172A;" vertex="1" parent="1"><mxGeometry x="120" y="16" width="640" height="36" as="geometry"/></mxCell>"""
)
add(
    """<mxCell id="subtitle" value="Connectors travel in the gaps between tables. Each relationship has its own color." style="text;html=1;fontSize=13;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontColor=#475569;" vertex="1" parent="1"><mxGeometry x="120" y="48" width="780" height="24" as="geometry"/></mxCell>"""
)

for name, ent in ENTITIES.items():
    fill, stroke = GROUPS[ent["group"]]
    add(
        f'''<mxCell id="{name}" value="{esc(ent["title"])}" style="shape=table;startSize={HEADER};container=1;collapsible=0;childLayout=tableLayout;fixedRows=1;rowLines=0;fontStyle=1;align=center;resizeLast=1;strokeColor={stroke};fillColor={fill};fontColor=#FFFFFF;fontSize=14;rounded=0;shadow=0;" vertex="1" parent="1"><mxGeometry x="{ent["x"]}" y="{ent["y"]}" width="{ent["w"]}" height="{ent["h"]}" as="geometry"/></mxCell>'''
    )
    for i, (mark, col, typ) in enumerate(ent["cols"]):
        y = HEADER + i * ROW_H
        rid = f"{name}_r{i}"
        mark_fill = {"PK": "#DBEAFE", "FK": "#FFEDD5", "UK": "#FCE7F3"}.get(mark, "#F8FAFC")
        mark_color = {"PK": "#1E3A8A", "FK": "#9A3412", "UK": "#9D174D"}.get(mark, "#64748B")
        label = f"{col}   {typ}"
        weight = "fontStyle=1;" if mark == "PK" else ""
        add(
            f'''<mxCell id="{rid}" style="shape=tableRow;horizontal=0;startSize=0;swimlaneHead=0;swimlaneBody=0;fillColor=#FFFFFF;collapsible=0;dropTarget=0;points=[[0,0.5],[1,0.5]];portConstraint=eastwest;top=0;left=0;right=0;bottom=0;" vertex="1" parent="{name}"><mxGeometry y="{y}" width="{ent["w"]}" height="{ROW_H}" as="geometry"/></mxCell>'''
        )
        add(
            f'''<mxCell id="{rid}_m" value="{esc(mark)}" style="shape=partialRectangle;connectable=0;fillColor={mark_fill};fontColor={mark_color};top=0;left=0;bottom=0;right=0;align=center;overflow=hidden;fontSize=10;fontStyle=1;" vertex="1" parent="{rid}"><mxGeometry width="36" height="{ROW_H}" as="geometry"><mxRectangle width="36" height="{ROW_H}" as="alternateBounds"/></mxGeometry></mxCell>'''
        )
        add(
            f'''<mxCell id="{rid}_c" value="{esc(label)}" style="shape=partialRectangle;connectable=0;fillColor=#FFFFFF;top=0;left=0;bottom=0;right=0;align=left;spacingLeft=8;overflow=hidden;fontSize=11;fontColor=#0F172A;{weight}" vertex="1" parent="{rid}"><mxGeometry x="36" width="{ent["w"] - 36}" height="{ROW_H}" as="geometry"><mxRectangle width="{ent["w"] - 36}" height="{ROW_H}" as="alternateBounds"/></mxGeometry></mxCell>'''
        )

failures = []
for rel in RELATIONS:
    pts = resolve_path(rel)
    problems = validate(rel, pts)
    if problems:
        failures.append((rel["id"], problems, pts))
    sx_side = rel["source"][2]
    tx_side = rel["target"][2]
    exit_x = 0 if sx_side == "left" else 1
    entry_x = 0 if tx_side == "left" else 1
    src = f'{rel["source"][0]}_r{rel["source"][1]}'
    tgt = f'{rel["target"][0]}_r{rel["target"][1]}'
    waypoints = pts[1:-1]
    point_xml = "".join(f'<mxPoint x="{x}" y="{y}"/>' for x, y in waypoints)
    style = (
        f'html=1;rounded=0;endFill=0;startFill=0;strokeWidth=2.5;jumpStyle=arc;jumpSize=12;'
        f'strokeColor={rel["color"]};startArrow={rel["start"]};endArrow={rel["end"]};'
        f'exitX={exit_x};exitY=0.5;exitDx=0;exitDy=0;entryX={entry_x};entryY=0.5;entryDx=0;entryDy=0;'
    )
    add(
        f'''<mxCell id="{rel["id"]}" value="" style="{style}" edge="1" parent="1" source="{src}" target="{tgt}"><mxGeometry relative="1" as="geometry"><Array as="points">{point_xml}</Array></mxGeometry></mxCell>'''
    )

# Legend sits below the tables, clear of every connector lane.
legend_y = 2140
add(
    f'''<mxCell id="legend" value="Relationship colors" style="swimlane;startSize=28;fillColor=#F8FAFC;strokeColor=#CBD5E1;fontStyle=1;fontSize=14;fontColor=#0F172A;rounded=0;" vertex="1" parent="1"><mxGeometry x="120" y="{legend_y}" width="1960" height="250" as="geometry"/></mxCell>'''
)
note = (
    "Crow’s foot is drawn at the row that owns the foreign key. "
    "PK / FK / UK markers sit on the left of each column. "
    "settings, discount_options, and truckings have no foreign keys. "
    "replaces_sale_id and replaced_by_sale_id are indexed self-references without a database foreign-key constraint. "
    "Purchases and suppliers were removed from the schema."
)
add(
    f'''<mxCell id="legend_note" value="{esc(note)}" style="text;html=1;align=left;verticalAlign=top;spacingLeft=8;spacingRight=8;fontSize=12;fontColor=#334155;strokeColor=none;fillColor=none;whiteSpace=wrap;" vertex="1" parent="legend"><mxGeometry x="8" y="32" width="1940" height="48" as="geometry"/></mxCell>'''
)
for i, rel in enumerate(RELATIONS):
    col = i % 2
    row = i // 2
    x = 16 + col * 980
    y = 84 + row * 16
    add(
        f'''<mxCell id="leg_{rel["id"]}" value="" style="endArrow=none;html=1;strokeWidth=4;strokeColor={rel["color"]};" edge="1" parent="legend"><mxGeometry relative="1" as="geometry"><mxPoint x="{x}" y="{y + 8}" as="sourcePoint"/><mxPoint x="{x + 36}" y="{y + 8}" as="targetPoint"/></mxGeometry></mxCell>'''
    )
    add(
        f'''<mxCell id="legt_{rel["id"]}" value="{esc(rel["label"])}" style="text;html=1;align=left;verticalAlign=middle;fontSize=11;fontColor=#0F172A;strokeColor=none;fillColor=none;" vertex="1" parent="legend"><mxGeometry x="{x + 44}" y="{y}" width="900" height="16" as="geometry"/></mxCell>'''
    )

page_w = 2360
page_h = 2460
body = "\n        ".join(cells)
xml = f'''<mxfile host="app.diagrams.net" agent="naac" version="22.1.0" type="device">
  <diagram id="naac-erd" name="NAAC ERD">
    <mxGraphModel dx="1600" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="{page_w}" pageHeight="{page_h}" math="0" shadow="0" background="#FFFFFF">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        {body}
      </root>
    </mxGraphModel>
  </diagram>
</mxfile>
'''

if failures:
    print("ROUTE FAILURES")
    for rel_id, problems, pts in failures:
        print(rel_id, pts)
        for seg in problems:
            print(" ", seg)
    raise SystemExit(1)

OUT.write_text(xml, encoding="utf-8")
print(f"Wrote {OUT} ({len(RELATIONS)} connectors, no table crossings)")
