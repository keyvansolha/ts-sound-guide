#!/usr/bin/env python3
"""Build a recommendable-catalog snapshot from the store's public Store API.

Used to reproduce WP-45 findings and produce the coverage report without
database access:

    python3 live_snapshot.py https://www.tehranspeaker.com live_catalog.json
    TSG_SRC=<plugin>/includes/src php probe.php live_catalog.json --json

The result mirrors CatalogAdapter's internal shape for every product the store
publicly offers: flow (earbuds/headphones), raw capability attributes,
category rows (with ancestors) and eligible variations (colour + guarantee,
in stock, priced). Public data only: managed stock depth and held reservations
are not exposed by the Store API and are not needed for eligibility here.
"""

import json
import sys
import urllib.request
from collections import Counter

TAX = [
    "pa_bluetooth", "pa_connection", "pa_aux", "pa_aux-microphone",
    "pa_noise-cancellation", "pa_qip5asto9pe6c2dzxq", "pa_inside-the-box",
    "pa_headphones-type",
]
VARIATION_LABEL_TAX = {"گارانتی": "pa_guarantee", "رنگ": "pa_color"}


def get(url):
    request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(request, timeout=120) as response:
        return json.load(response)


def main():
    origin = sys.argv[1] if len(sys.argv) > 1 else "https://www.tehranspeaker.com"
    out_path = sys.argv[2] if len(sys.argv) > 2 else "live_catalog.json"
    api = origin.rstrip("/") + "/wp-json/wc/store/v1/products"

    categories = get(api + "/categories?per_page=100")
    by_id = {category["id"]: category for category in categories}

    def tree(root):
        found, stack = [root], [root]
        while stack:
            current = stack.pop()
            for category in categories:
                if category["parent"] == current:
                    found.append(category["id"])
                    stack.append(category["id"])
        return found

    # Settings defaults resolve these store slugs: هندزفری and هدفون.
    flow_terms = {
        "earbuds": tree(next(c["id"] for c in categories if c["slug"] == "handsfree")),
        "headphones": tree(next(c["id"] for c in categories if c["slug"] == "headphone")),
    }

    products = {}
    for flow, term_ids in flow_terms.items():
        for term_id in term_ids:
            page = 1
            while page <= 20:
                batch = get(f"{api}?category={term_id}&per_page=100&page={page}&orderby=id&order=asc")
                if not batch:
                    break
                for product in batch:
                    entry = products.setdefault(product["id"], product)
                    entry.setdefault("_terms", []).append(term_id)
                if len(batch) < 100:
                    break
                page += 1

    def ancestors(term_id):
        rows, current = [], by_id.get(term_id)
        while current and current.get("parent"):
            rows.append(current["parent"])
            current = by_id.get(current["parent"])
        return rows

    catalog, skipped = [], Counter()
    for product in products.values():
        if not (product["is_in_stock"] and product["is_purchasable"]):
            skipped["out_of_stock_or_not_purchasable"] += 1
            continue
        assigned = set()
        for category in product.get("categories", []):
            assigned.add(category["id"])
            assigned.update(ancestors(category["id"]))
        for term_id in product.get("_terms", []):
            assigned.add(term_id)
            assigned.update(ancestors(term_id))
        flow = "earbuds" if assigned & set(flow_terms["earbuds"]) else "headphones"

        raw, slug_to_taxonomy = {}, {}
        for attribute in product.get("attributes", []):
            names = [term["name"] for term in attribute.get("terms", [])]
            if attribute["taxonomy"] in TAX and names:
                raw[attribute["taxonomy"]] = ", ".join(names)
            for term in attribute.get("terms", []):
                slug_to_taxonomy.setdefault(term["slug"], attribute["taxonomy"])

        variation_meta = {
            variation["id"]: variation.get("attributes", [])
            for variation in product.get("variations", [])
        }
        variations = get(f"{api}?type=variation&parent={product['id']}&per_page=100")
        variants = []
        for variation in variations:
            if not (variation["is_in_stock"] and variation["is_purchasable"]):
                continue
            price = float(variation["prices"]["price"] or 0)
            if price <= 0:
                continue
            attributes, labels, query = [], [], {}
            for attribute in variation_meta.get(variation["id"], []):
                taxonomy = slug_to_taxonomy.get(attribute["value"]) or VARIATION_LABEL_TAX.get(attribute["name"])
                if not taxonomy:
                    continue
                attributes.append({"name": attribute["name"], "slug": taxonomy, "option": attribute["value"]})
                labels.append(attribute["value"])
                query["attribute_" + taxonomy] = attribute["value"]
            if "attribute_pa_color" not in query or "attribute_pa_guarantee" not in query:
                continue
            variants.append({
                "id": variation["id"], "price": price, "qty": None, "held": 0.0, "inStock": True,
                "status": "publish", "stockStatus": "instock", "enabled": True, "backorder": False,
                "attributes": attributes, "label": " · ".join(labels), "query": query,
                "stockOwnerId": product["id"], "image": None,
            })
        if not variants:
            skipped["no_purchasable_variation"] += 1
            continue
        catalog.append({
            "id": product["id"], "wcId": product["id"], "name": product["name"], "flow": flow,
            "url": product["permalink"], "image": None, "form": raw.get("pa_headphones-type"),
            "capabilities": {}, "conflicts": {}, "categories": [
                {"id": str(term_id), "name": by_id[term_id]["name"], "slug": by_id[term_id]["slug"]}
                for term_id in sorted(assigned) if term_id in by_id
            ],
            "variants": variants, "status": "publish", "lifecycle": "active", "inStock": True,
            "purchasable": True, "cautions": [], "sources": [], "_raw": raw,
            "_price": float(product["prices"]["price"]),
        })

    with open(out_path, "w", encoding="utf-8") as handle:
        json.dump(catalog, handle, ensure_ascii=False)

    print("products in the two trees:", len(products))
    print("skipped:", dict(skipped))
    print("catalog entries:", len(catalog),
          "| earbuds:", sum(1 for row in catalog if row["flow"] == "earbuds"),
          "| headphones:", sum(1 for row in catalog if row["flow"] == "headphones"))
    print("written:", out_path)


if __name__ == "__main__":
    main()
