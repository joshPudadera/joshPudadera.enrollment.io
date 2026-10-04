"""
redact_document.py  —  PII redaction using fixed percentage zones
=================================================================
Draws solid black rectangles over known PII field VALUE areas on
Philippine enrollment documents. No OCR, no API calls — purely
geometric boxes calibrated to each form's standard layout.

The AI structural check (GPT-4o) runs SEPARATELY, triggered manually
by the admin via the "Check Structure" button in the Redaction Review tab.

Usage:
  python redact_document.py redact <input_path> <output_path> <doc_type>
  python redact_document.py <input_path> <output_path> <doc_type>   # legacy

doc_type:  BirthCertificate | ReportCard | GoodMoral

Requires: pip install Pillow
"""

import sys
import os
import json

# Add vendor path in case Pillow is there
VENDOR = os.path.normpath(os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', '..', 'vendor', 'py'
))
if os.path.isdir(VENDOR) and VENDOR not in sys.path:
    sys.path.insert(0, VENDOR)

try:
    from PIL import Image, ImageDraw
except ImportError:
    print(json.dumps({"success": False, "error": "Pillow not installed. Run: pip install Pillow"}))
    sys.exit(1)


# ─────────────────────────────────────────────────────────────────────────────
#  REDACTION ZONES
#  Each entry: (x_frac, y_frac, w_frac, h_frac) — fractions of image size.
#  Origin = top-left corner.
#  Zones cover only VALUE areas. Field labels are left visible.
#
#  PSA Form 102 (Revised January 1993) — calibrated to the sample image.
# ─────────────────────────────────────────────────────────────────────────────
ZONES = {
    "BirthCertificate": [
        # ── Header ───────────────────────────────────────────
        (0.10, 0.083, 0.36, 0.022),  # Province value
        (0.68, 0.083, 0.26, 0.022),  # Registry No. value
        (0.17, 0.105, 0.46, 0.022),  # City/Municipality value

        # ── Child (items 1–5) ─────────────────────────────────
        (0.06, 0.136, 0.25, 0.026),  # 1. First Name
        (0.32, 0.136, 0.22, 0.026),  # 1. Middle Name
        (0.56, 0.136, 0.24, 0.026),  # 1. Last Name
        (0.07, 0.162, 0.15, 0.022),  # 2. Sex checkbox tick
        (0.37, 0.162, 0.07, 0.022),  # 3. DOB day
        (0.45, 0.162, 0.13, 0.022),  # 3. DOB month
        (0.59, 0.162, 0.08, 0.022),  # 3. DOB year
        (0.06, 0.198, 0.76, 0.024),  # 4. Place of birth line 1
        (0.06, 0.222, 0.76, 0.020),  # 4. Place of birth line 2
        (0.07, 0.246, 0.13, 0.020),  # 5a. Type of birth tick
        (0.07, 0.274, 0.30, 0.020),  # 5c. Birth order value
        (0.40, 0.274, 0.22, 0.020),  # 5d. Weight value

        # ── Mother (items 6–12) ───────────────────────────────
        (0.06, 0.316, 0.25, 0.026),  # 6. First Name
        (0.32, 0.316, 0.22, 0.026),  # 6. Middle Name
        (0.56, 0.316, 0.24, 0.026),  # 6. Last Name (maiden)
        (0.06, 0.348, 0.27, 0.022),  # 7. Citizenship
        (0.40, 0.348, 0.25, 0.022),  # 8. Religion
        (0.07, 0.376, 0.09, 0.020),  # 9a. Total children
        (0.28, 0.376, 0.09, 0.020),  # 9b. Children still living
        (0.53, 0.376, 0.09, 0.020),  # 9c. Children now dead
        (0.06, 0.404, 0.32, 0.022),  # 10. Occupation
        (0.56, 0.404, 0.13, 0.022),  # 11. Age
        (0.06, 0.432, 0.76, 0.022),  # 12. Residence line 1
        (0.06, 0.452, 0.76, 0.018),  # 12. Residence line 2

        # ── Father (items 13–18) ──────────────────────────────
        (0.06, 0.490, 0.25, 0.026),  # 13. First Name
        (0.32, 0.490, 0.22, 0.026),  # 13. Middle Name
        (0.56, 0.490, 0.24, 0.026),  # 13. Last Name
        (0.06, 0.522, 0.27, 0.022),  # 14. Citizenship
        (0.40, 0.522, 0.25, 0.022),  # 15. Religion
        (0.06, 0.550, 0.32, 0.022),  # 16. Occupation
        (0.56, 0.550, 0.13, 0.022),  # 17. Age
        (0.06, 0.578, 0.76, 0.022),  # 18. Marriage date/place

        # ── Attendant (items 19a/b) ───────────────────────────
        (0.48, 0.632, 0.15, 0.020),  # 19b. Time value
        (0.06, 0.670, 0.33, 0.024),  # Signature/name
        (0.40, 0.670, 0.39, 0.024),  # Address line 1
        (0.06, 0.694, 0.31, 0.020),  # Name in print
        (0.40, 0.694, 0.39, 0.020),  # Address line 2
        (0.06, 0.714, 0.31, 0.018),  # Title/position
        (0.40, 0.710, 0.21, 0.018),  # Date

        # ── Informant (item 20) ───────────────────────────────
        (0.06, 0.746, 0.33, 0.024),  # Signature/name
        (0.40, 0.746, 0.39, 0.024),  # Address line 1
        (0.06, 0.770, 0.31, 0.020),  # Name in print
        (0.40, 0.770, 0.39, 0.020),  # Address line 2
        (0.40, 0.790, 0.21, 0.018),  # Date

        # ── Prepared By (item 21) ─────────────────────────────
        (0.06, 0.830, 0.31, 0.024),  # Signature/name
        (0.06, 0.854, 0.31, 0.020),  # Name in print
        (0.06, 0.874, 0.31, 0.018),  # Title/position
        (0.06, 0.893, 0.19, 0.016),  # Date

        # ── Received By / Civil Registrar (item 22) ───────────
        (0.40, 0.830, 0.39, 0.024),  # Signature/name
        (0.40, 0.854, 0.39, 0.020),  # Name in print
        (0.40, 0.874, 0.39, 0.018),  # Title/position
        (0.40, 0.893, 0.19, 0.016),  # Date
    ],

    "ReportCard": [
        (0.18, 0.055, 0.43, 0.030),  # Student name
        (0.68, 0.055, 0.28, 0.030),  # LRN
        (0.18, 0.092, 0.23, 0.028),  # Date of birth
        (0.44, 0.092, 0.09, 0.028),  # Age
        (0.57, 0.092, 0.09, 0.028),  # Sex
        (0.18, 0.125, 0.37, 0.028),  # Mother's name
        (0.61, 0.125, 0.37, 0.028),  # Father's/guardian name
        (0.12, 0.158, 0.86, 0.028),  # Address
        (0.12, 0.880, 0.31, 0.026),  # Class adviser
        (0.63, 0.880, 0.34, 0.026),  # Principal
        (0.12, 0.908, 0.23, 0.022),  # Date
    ],

    "GoodMoral": [
        (0.10, 0.295, 0.80, 0.040),  # Recipient name — body line 1
        (0.10, 0.355, 0.80, 0.040),  # Recipient name — body line 2
        (0.10, 0.415, 0.80, 0.038),  # Course/program
        (0.25, 0.778, 0.50, 0.032),  # Signatory printed name
        (0.25, 0.812, 0.50, 0.028),  # Signatory position
    ],
}


def redact_image(input_path: str, output_path: str, doc_type: str) -> dict:
    if doc_type not in ZONES:
        return {"success": False,
                "error": f"Unknown doc_type '{doc_type}'. Supported: {', '.join(ZONES)}"}
    if not os.path.exists(input_path):
        return {"success": False, "error": f"Input not found: {input_path}"}
    try:
        img  = Image.open(input_path).convert("RGB")
        draw = ImageDraw.Draw(img)
        W, H = img.size
        for (xp, yp, wp, hp) in ZONES[doc_type]:
            x0 = max(0, int(xp * W))
            y0 = max(0, int(yp * H))
            x1 = min(W - 1, int((xp + wp) * W))
            y1 = min(H - 1, int((yp + hp) * H))
            draw.rectangle([x0, y0, x1, y1], fill=(0, 0, 0))
        os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
        img.save(output_path, quality=92)
        return {"success": True, "redacted_path": output_path}
    except Exception as exc:
        return {"success": False, "error": str(exc)}


if __name__ == "__main__":
    # Accept both:
    #   redact_document.py redact <input> <output> <doc_type>
    #   redact_document.py <input> <output> <doc_type>   (legacy)
    args = sys.argv[1:]
    if args and args[0].lower() == "redact":
        args = args[1:]
    if len(args) < 3:
        print(json.dumps({"success": False,
                          "error": "Usage: redact_document.py redact <input> <output> <doc_type>"}))
        sys.exit(1)
    result = redact_image(args[0], args[1], args[2])
    print(json.dumps(result))
    sys.exit(0 if result["success"] else 1)
