"""KPI diagram for NAAC. Connectors stay in the gutters beside the cards."""
import html
from pathlib import Path

OUT = Path(__file__).with_name("NAAC-KPI.drawio")

CARD_W = 430
CARD_H = 112
COL_X = [130, 680, 1230, 1780]
GOAL_Y, GOAL_H = 36, 78
CAT_Y, CAT_H = 200, 64
KPI_Y0 = 340
KPI_GAP = 28

COLUMNS = [
    {
        "id": "sales",
        "title": "Sales performance",
        "fill": "#1D4ED8",
        "stroke": "#1E3A8A",
        "kpis": [
            ("Collected sales", "Sum of sales.total_price on collected sales. Unpaid utang is excluded until paid_at is set.", "sales"),
            ("Sales by branch", "Collected sales grouped by the cashier’s branch, for the selected date range.", "sales, users, employees, branches"),
            ("Top products", "Units sold per product, ranked. The dashboard pie uses this quantity.", "sales, products"),
            ("VAT collected", "Sum of sales.vat_amount on collected sales.", "sales"),
            ("Discounts given", "Sum of sales.discount_amount on collected sales.", "sales, discount_options"),
        ],
    },
    {
        "id": "inventory",
        "title": "Inventory health",
        "fill": "#047857",
        "stroke": "#064E3B",
        "kpis": [
            ("On-hand quantity", "Sum of inventories.quantity. This is the dashboard inventory count.", "inventories"),
            ("Low stock", "Batches whose quantity is at or below settings.low_stock_threshold.", "inventories, settings"),
            ("Expected revenue", "Logged as price times quantity when stock is received or adjusted.", "inventory_revenue_logs"),
            ("Catalog size", "Count of products. Branch managers see products stocked in their branch.", "products, inventories"),
            ("Retail stock", "Wholesale quantity plus inventories.retail_remainder for retail sales.", "inventories, products"),
        ],
    },
    {
        "id": "credit",
        "title": "Credit and collections",
        "fill": "#C2410C",
        "stroke": "#9A3412",
        "kpis": [
            ("Unpaid utang", "payment_method is utang and paid_at is empty.", "sales"),
            ("Overdue utang", "Unpaid utang whose due_date is before today.", "sales"),
            ("Collected utang", "Utang with paid_at set. It then counts as collected sales.", "sales"),
            ("Utang exposure", "Sum of total_price on unpaid utang tickets.", "sales"),
        ],
    },
    {
        "id": "cashier",
        "title": "Cashier control",
        "fill": "#7E22CE",
        "stroke": "#581C87",
        "kpis": [
            ("Receipts", "daily_sales_reports.receipt_count for the submitted day.", "daily_sales_reports"),
            ("Day sales", "total_sales, total_vat, and total_discount on the daily report.", "daily_sales_reports"),
            ("Cash counted", "cash_counted entered by the cashier against that day’s sales.", "daily_sales_reports"),
            ("Replacement extra", "Additional amount collected when a product is replaced.", "daily_sales_reports, product_replacements"),
            ("Active accounts", "Users with is_active set. The dashboard also counts total users.", "users"),
        ],
    },
]

# One color per connector.
EDGE_COLORS = [
    "#E11D48", "#2563EB", "#059669", "#7C3AED",
    "#DC2626", "#D97706", "#0891B2", "#9333EA", "#DB2777",
    "#16A34A", "#0D9488", "#65A30D", "#0F766E", "#A16207",
    "#EA580C", "#B45309", "#BE185D", "#9F1239",
    "#4F46E5", "#0369A1", "#C026D3", "#0E7490", "#854D0E",
]


def esc(text):
    return html.escape(text, quote=True)


boxes = []
goal = {"id": "goal", "x": 900, "y": GOAL_Y, "w": 560, "h": GOAL_H}
boxes.append(goal)

categories = []
kpis = []
for col_index, column in enumerate(COLUMNS):
    x = COL_X[col_index]
    cat = {
        "id": f"cat_{column['id']}",
        "x": x,
        "y": CAT_Y,
        "w": CARD_W,
        "h": CAT_H,
        "column": column,
    }
    categories.append(cat)
    boxes.append(cat)
    for kpi_index, (title, measure, source) in enumerate(column["kpis"]):
        kpi = {
            "id": f"kpi_{column['id']}_{kpi_index}",
            "x": x,
            "y": KPI_Y0 + kpi_index * (CARD_H + KPI_GAP),
            "w": CARD_W,
            "h": CARD_H,
            "title": title,
            "measure": measure,
            "source": source,
            "column": column,
        }
        kpis.append(kpi)
        boxes.append(kpi)


def hits(x1, y1, x2, y2, ignore):
    if x1 != x2 and y1 != y2:
        return ["diagonal"]
    found = []
    for box in boxes:
        if box["id"] in ignore:
            continue
        left, top = box["x"] + 1, box["y"] + 1
        right, bottom = box["x"] + box["w"] - 1, box["y"] + box["h"] - 1
        if x1 == x2:
            yy1, yy2 = sorted((y1, y2))
            if left < x1 < right and yy2 > top and yy1 < bottom:
                found.append(box["id"])
        else:
            xx1, xx2 = sorted((x1, x2))
            if top < y1 < bottom and xx2 > left and xx1 < right:
                found.append(box["id"])
    return found


edges = []
color_at = 0


def take_color():
    global color_at
    color = EDGE_COLORS[color_at % len(EDGE_COLORS)]
    color_at += 1
    return color


# One color per column. Outer columns use the upper lane and inner columns
# the lower lane, so the four top lines never cross. Cards then chain
# straight down through the gap between them.
goal_bottom = goal["y"] + goal["h"]
goal_left = goal["x"]
upper_bus = goal_bottom + 18
lower_bus = goal_bottom + 46
# exit fraction on the goal bottom, bus y, for sales, inventory, credit, cashier
top_routes = [
    (0.07, upper_bus),
    (0.28, lower_bus),
    (0.72, lower_bus),
    (0.93, upper_bus),
]
for index, cat in enumerate(categories):
    color = cat["column"]["fill"]
    exit_frac, bus_y = top_routes[index]
    exit_x = goal_left + goal["w"] * exit_frac
    entry_x = cat["x"] + cat["w"] / 2
    pts = [
        (exit_x, goal_bottom),
        (exit_x, bus_y),
        (entry_x, bus_y),
        (entry_x, cat["y"]),
    ]
    edges.append({
        "id": f"e_goal_{cat['id']}",
        "color": color,
        "source": "goal",
        "target": cat["id"],
        "exit": (exit_frac, 1),
        "entry": (0.5, 0),
        "pts": pts,
        "ignore": {"goal", cat["id"]},
    })

for cat in categories:
    color = cat["column"]["fill"]
    column_kpis = [kpi for kpi in kpis if kpi["column"]["id"] == cat["column"]["id"]]
    cx = cat["x"] + cat["w"] / 2
    previous = cat
    for kpi in column_kpis:
        pts = [
            (cx, previous["y"] + previous["h"]),
            (cx, kpi["y"]),
        ]
        edges.append({
            "id": f"e_{kpi['id']}",
            "color": color,
            "source": previous["id"],
            "target": kpi["id"],
            "exit": (0.5, 1),
            "entry": (0.5, 0),
            "pts": pts,
            "ignore": {previous["id"], kpi["id"]},
        })
        previous = kpi

failures = []
for edge in edges:
    pts = edge["pts"]
    for a, b in zip(pts, pts[1:]):
        problem = hits(*a, *b, edge["ignore"])
        if problem:
            failures.append((edge["id"], a, b, problem))

cells = []
cells.append(
    '<mxCell id="title" value="NAAC key performance indicators" style="text;html=1;fontSize=22;fontStyle=1;align=left;verticalAlign=middle;strokeColor=none;fillColor=none;fontColor=#0F172A;" vertex="1" parent="1"><mxGeometry x="130" y="8" width="720" height="28" as="geometry"/></mxCell>'
)

goal_value = (
    "&lt;b&gt;Collected sales&lt;/b&gt;&lt;br&gt;"
    "&lt;font style=&quot;font-size:12px&quot;&gt;Cash and other non-utang sales, plus utang only after it is paid.&lt;/font&gt;"
)
cells.append(
    f'<mxCell id="goal" value="{goal_value}" style="rounded=1;whiteSpace=wrap;html=1;arcSize=8;fillColor=#0F172A;fontColor=#FFFFFF;strokeColor=#020617;fontSize=16;verticalAlign=middle;align=center;spacing=8;" vertex="1" parent="1"><mxGeometry x="{goal["x"]}" y="{goal["y"]}" width="{goal["w"]}" height="{goal["h"]}" as="geometry"/></mxCell>'
)

for cat in categories:
    col = cat["column"]
    cells.append(
        f'<mxCell id="{cat["id"]}" value="{esc(col["title"])}" style="rounded=0;whiteSpace=wrap;html=1;fillColor={col["fill"]};fontColor=#FFFFFF;strokeColor={col["stroke"]};fontSize=15;fontStyle=1;verticalAlign=middle;align=center;" vertex="1" parent="1"><mxGeometry x="{cat["x"]}" y="{cat["y"]}" width="{cat["w"]}" height="{cat["h"]}" as="geometry"/></mxCell>'
    )

for kpi in kpis:
    col = kpi["column"]
    value = (
        f"&lt;b&gt;{esc(kpi['title'])}&lt;/b&gt;&lt;br&gt;"
        f"&lt;font style=&quot;font-size:11px&quot; color=&quot;#334155&quot;&gt;{esc(kpi['measure'])}&lt;/font&gt;&lt;br&gt;"
        f"&lt;font style=&quot;font-size:10px&quot; color=&quot;#64748B&quot;&gt;Source: {esc(kpi['source'])}&lt;/font&gt;"
    )
    cells.append(
        f'<mxCell id="{kpi["id"]}" value="{value}" style="rounded=0;whiteSpace=wrap;html=1;align=left;verticalAlign=middle;spacingLeft=12;spacingRight=10;fillColor=#FFFFFF;strokeColor={col["stroke"]};fontColor=#0F172A;fontSize=13;" vertex="1" parent="1"><mxGeometry x="{kpi["x"]}" y="{kpi["y"]}" width="{kpi["w"]}" height="{kpi["h"]}" as="geometry"/></mxCell>'
    )

for edge in edges:
    waypoints = edge["pts"][1:-1]
    point_xml = "".join(f'<mxPoint x="{x}" y="{y}"/>' for x, y in waypoints)
    ex, ey = edge["exit"]
    ix, iy = edge["entry"]
    style = (
        f"html=1;rounded=0;endArrow=block;endFill=1;startArrow=none;strokeWidth=2.5;"
        f"jumpStyle=arc;jumpSize=10;strokeColor={edge['color']};"
        f"exitX={ex};exitY={ey};exitDx=0;exitDy=0;entryX={ix};entryY={iy};entryDx=0;entryDy=0;"
    )
    cells.append(
        f'<mxCell id="{edge["id"]}" value="" style="{style}" edge="1" parent="1" source="{edge["source"]}" target="{edge["target"]}"><mxGeometry relative="1" as="geometry"><Array as="points">{point_xml}</Array></mxGeometry></mxCell>'
    )

legend_items = [
    ("#1D4ED8", "Sales — what was sold and recognized as collected"),
    ("#047857", "Inventory — stock on hand, low stock, and expected revenue"),
    ("#C2410C", "Credit — utang that is open, overdue, or collected"),
    ("#7E22CE", "Cashier — the daily report a cashier submits for a branch"),
]
legend_y = KPI_Y0 + 5 * (CARD_H + KPI_GAP) + 24
cells.append(
    f'<mxCell id="legend" value="How to read this" style="swimlane;startSize=28;fillColor=#F8FAFC;strokeColor=#CBD5E1;fontStyle=1;fontSize=14;fontColor=#0F172A;rounded=0;" vertex="1" parent="1"><mxGeometry x="130" y="{legend_y}" width="2080" height="120" as="geometry"/></mxCell>'
)
for i, (color, label) in enumerate(legend_items):
    y = 40 + i * 18
    cells.append(
        f'<mxCell id="leg_{i}" value="" style="endArrow=none;html=1;strokeWidth=6;strokeColor={color};" edge="1" parent="legend"><mxGeometry relative="1" as="geometry"><mxPoint x="16" y="{y}" as="sourcePoint"/><mxPoint x="52" y="{y}" as="targetPoint"/></mxGeometry></mxCell>'
    )
    cells.append(
        f'<mxCell id="legt_{i}" value="{esc(label)}" style="text;html=1;align=left;verticalAlign=middle;fontSize=12;fontColor=#0F172A;strokeColor=none;fillColor=none;" vertex="1" parent="legend"><mxGeometry x="64" y="{y - 10}" width="900" height="20" as="geometry"/></mxCell>'
    )

if failures:
    print("ROUTE FAILURES")
    for item in failures:
        print(item)
    raise SystemExit(1)

page_h = int(legend_y + 180)
xml = f'''<mxfile host="app.diagrams.net" agent="naac" version="22.1.0" type="device">
  <diagram id="naac-kpi" name="NAAC KPI">
    <mxGraphModel dx="1600" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="2400" pageHeight="{page_h}" math="0" shadow="0" background="#FFFFFF">
      <root>
        <mxCell id="0"/>
        <mxCell id="1" parent="0"/>
        {chr(10).join(cells)}
      </root>
    </mxGraphModel>
  </diagram>
</mxfile>
'''
OUT.write_text(xml, encoding="utf-8")
print(f"Wrote {OUT} ({len(edges)} connectors, {len(kpis)} KPIs)")
