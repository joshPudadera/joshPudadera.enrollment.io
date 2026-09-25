"""
generate_docx.py
================
Builds a structured Word document (.docx) from AI-extracted enrollment data.

Called by generate_docx.php:
    python generate_docx.py <payload_json_path> <output_docx_path>

Requires:
    pip install python-docx

──────────────────────────────────────────────────────────────────────────────
DOCUMENT TEMPLATE STRUCTURE
──────────────────────────────────────────────────────────────────────────────
When you create your Word template in the future, map these fields:

SECTION 1 — APPLICANT INFORMATION
  TODO: [APPLICANT_REF_NUMBER]       Application reference number
  TODO: [APPLICANT_FULL_NAME]        Full name (Last, First Middle)
  TODO: [APPLICANT_FIRST_NAME]       First name
  TODO: [APPLICANT_LAST_NAME]        Last name
  TODO: [APPLICANT_MIDDLE_NAME]      Middle name
  TODO: [APPLICANT_EMAIL]            Email address
  TODO: [APPLICANT_PHONE]            Phone / mobile number
  TODO: [APPLICANT_ADDRESS]          Complete home address
  TODO: [APPLICANT_COURSE]           Course applied for
  TODO: [APPLICANT_YEAR_LEVEL]       Year level
  TODO: [APPLICANT_BRANCH]           Campus/branch
  TODO: [APPLICANT_SUBMITTED_AT]     Date application was submitted

SECTION 2 — BIRTH CERTIFICATE (PSA)
  TODO: [BC_FULL_NAME]               Full name as on PSA cert
  TODO: [BC_LAST_NAME]               Last name
  TODO: [BC_FIRST_NAME]              First name
  TODO: [BC_MIDDLE_NAME]             Middle name
  TODO: [BC_DATE_OF_BIRTH]           Date of birth (YYYY-MM-DD)
  TODO: [BC_PLACE_OF_BIRTH]          Place of birth
  TODO: [BC_SEX]                     Sex (Male/Female)
  TODO: [BC_NATIONALITY]             Nationality
  TODO: [BC_REGISTRATION_NUMBER]     PSA Registry number
  TODO: [BC_DATE_ISSUED]             Date the cert was issued
  TODO: [BC_ISSUING_AUTHORITY]       Issuing authority (PSA / civil registrar)
  TODO: [BC_NAME_OF_MOTHER]          Mother's full maiden name
  TODO: [BC_NAME_OF_FATHER]          Father's full name
  TODO: [BC_AI_VERDICT]              AI authenticity verdict
  TODO: [BC_AI_CONFIDENCE]           AI confidence score (%)
  TODO: [BC_AI_NOTES]                AI notes / flags

SECTION 3 — REPORT CARD (Form 138)
  TODO: [RC_FULL_NAME]               Student name on report card
  TODO: [RC_LRN]                     Learner Reference Number
  TODO: [RC_SCHOOL_NAME]             School name
  TODO: [RC_SCHOOL_YEAR]             School year (e.g. 2024-2025)
  TODO: [RC_GRADE_LEVEL]             Grade level (e.g. Grade 12)
  TODO: [RC_STRAND_OR_TRACK]         Strand/track (e.g. STEM, ABM)
  TODO: [RC_GENERAL_AVERAGE]         Final general average
  TODO: [RC_CLASS_ADVISER]           Class adviser name
  TODO: [RC_PRINCIPAL]               School principal name
  TODO: [RC_DATE_ISSUED]             Date issued
  TODO: [RC_ISSUING_AUTHORITY]       Issuing school + principal
  TODO: [RC_AI_VERDICT]              AI authenticity verdict
  TODO: [RC_AI_CONFIDENCE]           AI confidence score (%)
  TODO: [RC_AI_NOTES]                AI notes / flags

SECTION 4 — GOOD MORAL CERTIFICATE
  TODO: [GM_FULL_NAME]               Recipient full name
  TODO: [GM_ISSUING_SCHOOL]          School / institution that issued it
  TODO: [GM_ISSUING_AUTHORITY]       Signatory name + position
  TODO: [GM_DATE_ISSUED]             Date issued
  TODO: [GM_PURPOSE]                 Stated purpose (e.g. "for college enrollment")
  TODO: [GM_YEAR_GRADUATED]          Year graduated / SY covered
  TODO: [GM_AI_VERDICT]              AI authenticity verdict
  TODO: [GM_AI_CONFIDENCE]           AI confidence score (%)
  TODO: [GM_AI_NOTES]                AI notes / flags

SECTION 5 — DOCUMENT STATUSES
  TODO: [STATUS_BIRTH_CERTIFICATE]   Admin approval status
  TODO: [STATUS_REPORT_CARD]         Admin approval status
  TODO: [STATUS_GOOD_MORAL]          Admin approval status

SECTION 6 — GENERATION META
  TODO: [GENERATED_AT]               Timestamp this document was generated
  TODO: [GENERATED_BY]               Admin who triggered generation
──────────────────────────────────────────────────────────────────────────────
"""

import sys
import json
import os
from datetime import datetime

try:
    from docx import Document
    from docx.shared import Pt, RGBColor, Inches, Cm
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
    from docx.oxml.ns import qn
    from docx.oxml import OxmlElement
except ImportError:
    print("ERROR: python-docx is not installed. Run:  pip install python-docx", file=sys.stderr)
    sys.exit(1)


# ── Colour palette (matches the BCP blue theme) ───────────────
BLUE_DARK   = RGBColor(0x1a, 0x3a, 0x8c)   # #1a3a8c
BLUE_MID    = RGBColor(0x25, 0x63, 0xeb)   # #2563eb
BLUE_LIGHT  = RGBColor(0xef, 0xf6, 0xff)   # #eff6ff (table header bg)
GREEN       = RGBColor(0x16, 0xa3, 0x4a)   # #16a34a
RED         = RGBColor(0xdc, 0x26, 0x26)   # #dc2626
AMBER       = RGBColor(0xd9, 0x77, 0x06)   # #d97706
GREY_TEXT   = RGBColor(0x55, 0x55, 0x55)   # #555
GREY_LIGHT  = RGBColor(0x88, 0x88, 0x88)   # #888


# ═══════════════════════════════════════════════════════════════
#  HELPERS
# ═══════════════════════════════════════════════════════════════

def val(data: dict, *keys, default='—') -> str:
    """Safely pull a nested value from a dict; return default if missing/None."""
    for key in keys:
        if isinstance(data, dict):
            data = data.get(key)
        else:
            return default
    return str(data).strip() if data else default


def verdict_label(ai_data: dict) -> tuple[str, RGBColor]:
    """Return (label, colour) for an AI authenticity verdict."""
    v = ai_data.get('is_authentic')
    if v is True:
        return '✓ Authentic', GREEN
    if v is False:
        return '✗ Suspicious / Possibly Fake', RED
    return '? Uncertain', AMBER


def set_cell_bg(cell, hex_color: str):
    """Set table cell background colour."""
    tc   = cell._tc
    tcPr = tc.get_or_add_tcPr()
    shd  = OxmlElement('w:shd')
    shd.set(qn('w:val'),   'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'),  hex_color)
    tcPr.append(shd)


def add_section_heading(doc: Document, title: str, icon: str = ''):
    """Add a bold blue section heading with a bottom border."""
    p    = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(14)
    p.paragraph_format.space_after  = Pt(4)
    run  = p.add_run((icon + '  ' + title).strip())
    run.bold      = True
    run.font.size = Pt(11)
    run.font.color.rgb = BLUE_DARK

    # Bottom border on the paragraph
    pPr  = p._p.get_or_add_pPr()
    pBdr = OxmlElement('w:pBdr')
    bot  = OxmlElement('w:bottom')
    bot.set(qn('w:val'),   'single')
    bot.set(qn('w:sz'),    '4')
    bot.set(qn('w:space'), '1')
    bot.set(qn('w:color'), '1a3a8c')
    pBdr.append(bot)
    pPr.append(pBdr)
    return p


def add_field_table(doc: Document, fields: list[tuple[str, str]]):
    """
    Render a two-column label/value table.
    fields = [(label, value), ...]
    """
    table = doc.add_table(rows=0, cols=2)
    table.style = 'Table Grid'
    table.alignment = WD_TABLE_ALIGNMENT.LEFT

    # Column widths
    for row_data in fields:
        row   = table.add_row()
        label_cell = row.cells[0]
        value_cell = row.cells[1]

        # Label cell
        set_cell_bg(label_cell, 'f1f5f9')
        label_cell.width = Cm(5.5)
        lp   = label_cell.paragraphs[0]
        lp.paragraph_format.space_before = Pt(3)
        lp.paragraph_format.space_after  = Pt(3)
        lr   = lp.add_run(row_data[0])
        lr.bold           = True
        lr.font.size      = Pt(8.5)
        lr.font.color.rgb = GREY_TEXT

        # Value cell
        value_cell.width = Cm(11)
        vp   = value_cell.paragraphs[0]
        vp.paragraph_format.space_before = Pt(3)
        vp.paragraph_format.space_after  = Pt(3)
        vr   = vp.add_run(row_data[1])
        vr.font.size      = Pt(9)
        vr.font.color.rgb = RGBColor(0x1a, 0x1a, 0x2e)

    return table


def add_ai_verdict_block(doc: Document, ai_data: dict):
    """Add a compact AI verdict + notes + red flags block."""
    label, colour = verdict_label(ai_data)
    conf          = ai_data.get('confidence', 0)
    notes         = ai_data.get('notes', '') or ai_data.get('authenticity_notes', '')
    flags         = ai_data.get('red_flags', [])
    inspected     = ai_data.get('inspected_at', '')

    # Verdict line
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(6)
    p.paragraph_format.space_after  = Pt(2)
    r = p.add_run(f'AI Verdict:  {label}   ({conf}% confidence)')
    r.bold            = True
    r.font.size       = Pt(9)
    r.font.color.rgb  = colour

    if inspected:
        p2 = doc.add_paragraph()
        p2.paragraph_format.space_before = Pt(0)
        p2.paragraph_format.space_after  = Pt(2)
        r2 = p2.add_run(f'Inspected at: {inspected}')
        r2.font.size      = Pt(7.5)
        r2.font.color.rgb = GREY_LIGHT

    if notes:
        p3 = doc.add_paragraph()
        p3.paragraph_format.space_before = Pt(2)
        p3.paragraph_format.space_after  = Pt(2)
        r3l = p3.add_run('Notes: ')
        r3l.bold           = True
        r3l.font.size      = Pt(8.5)
        r3l.font.color.rgb = GREY_TEXT
        r3v = p3.add_run(notes)
        r3v.font.size      = Pt(8.5)
        r3v.font.color.rgb = GREY_TEXT

    if flags:
        pf = doc.add_paragraph()
        pf.paragraph_format.space_before = Pt(2)
        pf.paragraph_format.space_after  = Pt(2)
        rf = pf.add_run('Red Flags:')
        rf.bold            = True
        rf.font.size       = Pt(8.5)
        rf.font.color.rgb  = RED
        for flag in flags:
            pfi = doc.add_paragraph(style='List Bullet')
            pfi.paragraph_format.left_indent = Cm(1)
            rfi = pfi.add_run(flag)
            rfi.font.size      = Pt(8.5)
            rfi.font.color.rgb = RED


# ═══════════════════════════════════════════════════════════════
#  DOCUMENT BUILDER
# ═══════════════════════════════════════════════════════════════

def build_document(payload: dict, output_path: str):
    doc = Document()

    # ── Page margins ─────────────────────────────────────────
    for section in doc.sections:
        section.top_margin    = Cm(2.0)
        section.bottom_margin = Cm(2.0)
        section.left_margin   = Cm(2.5)
        section.right_margin  = Cm(2.5)

    applicant = payload.get('applicant', {})
    bc        = payload.get('birth_certificate', {})
    rc        = payload.get('report_card', {})
    gm        = payload.get('good_moral', {})
    statuses  = payload.get('statuses', {})
    meta      = {
        'generated_at': payload.get('generated_at', ''),
        'generated_by': payload.get('generated_by', ''),
    }

    # ── Resolve best full name (prefer AI from birth cert) ────
    full_name = (
        val(bc, 'full_name', default='') or
        f"{val(applicant,'last_name',default='')} {val(applicant,'first_name',default='')} {val(applicant,'middle_name',default='')}".strip()
        or '—'
    )

    # ════════════════════════════════════════════════════════
    #  HEADER
    # ════════════════════════════════════════════════════════
    title_p = doc.add_paragraph()
    title_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    title_p.paragraph_format.space_after = Pt(2)
    tr = title_p.add_run('BULACAN CHRISTIAN POLYTECHNIC COLLEGE')
    tr.bold            = True
    tr.font.size       = Pt(14)
    tr.font.color.rgb  = BLUE_DARK

    sub_p = doc.add_paragraph()
    sub_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    sub_p.paragraph_format.space_after = Pt(2)
    sr = sub_p.add_run('Office of the Registrar — Admission Document Summary')
    sr.font.size      = Pt(10)
    sr.font.color.rgb = BLUE_MID

    date_p = doc.add_paragraph()
    date_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    date_p.paragraph_format.space_after = Pt(10)
    dr = date_p.add_run(f'Generated: {meta["generated_at"]}   |   By: {meta["generated_by"]}')
    dr.font.size      = Pt(8)
    dr.font.color.rgb = GREY_LIGHT

    # Horizontal rule
    hr = doc.add_paragraph()
    hr.paragraph_format.space_after = Pt(6)
    hrPr = hr._p.get_or_add_pPr()
    hrBdr = OxmlElement('w:pBdr')
    hrBot = OxmlElement('w:bottom')
    hrBot.set(qn('w:val'),   'single')
    hrBot.set(qn('w:sz'),    '6')
    hrBot.set(qn('w:space'), '1')
    hrBot.set(qn('w:color'), '1a3a8c')
    hrBdr.append(hrBot)
    hrPr.append(hrBdr)

    # ════════════════════════════════════════════════════════
    #  SECTION 1 — APPLICANT INFORMATION
    # ════════════════════════════════════════════════════════
    add_section_heading(doc, 'APPLICANT INFORMATION', '1.')

    add_field_table(doc, [
        # TODO: These pull from pre_registrations. Add missing columns to that table.
        ('Reference No.',   val(applicant, 'ref_number')),
        ('Full Name',       full_name),
        ('Email',           val(applicant, 'email')),
        ('Phone',           val(applicant, 'phone')),
        ('Address',         val(applicant, 'address')),
        # TODO: [APPLICANT_ADDRESS] — add `address` column to pre_registrations
        ('Course Applied',  val(applicant, 'course')),
        ('Year Level',      val(applicant, 'year_level')),
        ('Campus / Branch', val(applicant, 'branch')),
        # TODO: [APPLICANT_BRANCH] — add `branch` column to pre_registrations
        ('Date Submitted',  val(applicant, 'submitted_at')),
    ])

    # ════════════════════════════════════════════════════════
    #  SECTION 2 — PSA BIRTH CERTIFICATE
    # ════════════════════════════════════════════════════════
    add_section_heading(doc, 'PSA BIRTH CERTIFICATE', '2.')

    # Document status badge
    bc_status = statuses.get('BirthCertificate', 'Pending')
    sp = doc.add_paragraph()
    sp.paragraph_format.space_before = Pt(2)
    sp.paragraph_format.space_after  = Pt(4)
    sr2 = sp.add_run(f'Admin Status: {bc_status}')
    sr2.bold           = True
    sr2.font.size      = Pt(9)
    sr2.font.color.rgb = GREEN if bc_status == 'Approved' else (RED if bc_status == 'Rejected' else AMBER)

    add_field_table(doc, [
        ('Full Name',            val(bc, 'full_name')),
        ('Last Name',            val(bc, 'last_name')),
        ('First Name',           val(bc, 'first_name')),
        ('Middle Name',          val(bc, 'middle_name')),
        ('Date of Birth',        val(bc, 'date_of_birth')),
        ('Place of Birth',       val(bc, 'place_of_birth')),
        ('Sex',                  val(bc, 'sex')),
        ('Nationality',          val(bc, 'nationality')),
        ('PSA Registry No.',     val(bc, 'registration_number')),
        ('Date Issued',          val(bc, 'date_issued')),
        ('Issuing Authority',    val(bc, 'issuing_authority')),
        ("Mother's Name",        val(bc, 'name_of_mother')),
        ("Father's Name",        val(bc, 'name_of_father')),
        # TODO: [BC_NAME_OF_MOTHER] / [BC_NAME_OF_FATHER] — fill in your template here
    ])

    add_ai_verdict_block(doc, bc)

    # ════════════════════════════════════════════════════════
    #  SECTION 3 — REPORT CARD (Form 138)
    # ════════════════════════════════════════════════════════
    add_section_heading(doc, 'REPORT CARD (Form 138)', '3.')

    rc_status = statuses.get('ReportCard', 'Pending')
    sp3 = doc.add_paragraph()
    sp3.paragraph_format.space_before = Pt(2)
    sp3.paragraph_format.space_after  = Pt(4)
    sr3 = sp3.add_run(f'Admin Status: {rc_status}')
    sr3.bold           = True
    sr3.font.size      = Pt(9)
    sr3.font.color.rgb = GREEN if rc_status == 'Approved' else (RED if rc_status == 'Rejected' else AMBER)

    add_field_table(doc, [
        ('Student Name',          val(rc, 'full_name')),
        ('Last Name',             val(rc, 'last_name')),
        ('First Name',            val(rc, 'first_name')),
        ('Middle Name',           val(rc, 'middle_name')),
        ('LRN',                   val(rc, 'lrn')),
        # TODO: [RC_LRN] — cross-check with DepEd LIS if possible
        ('School Name',           val(rc, 'school_name')),
        ('School Year',           val(rc, 'school_year')),
        ('Grade Level',           val(rc, 'grade_level')),
        ('Strand / Track',        val(rc, 'strand_or_track')),
        ('General Average',       val(rc, 'general_average')),
        ('Class Adviser',         val(rc, 'class_adviser')),
        ('School Principal',      val(rc, 'principal')),
        ('Date Issued',           val(rc, 'date_issued')),
        ('Issuing Authority',     val(rc, 'issuing_authority')),
    ])

    add_ai_verdict_block(doc, rc)

    # ════════════════════════════════════════════════════════
    #  SECTION 4 — GOOD MORAL CERTIFICATE
    # ════════════════════════════════════════════════════════
    add_section_heading(doc, 'CERTIFICATE OF GOOD MORAL CHARACTER', '4.')

    gm_status = statuses.get('GoodMoral', 'Pending')
    sp4 = doc.add_paragraph()
    sp4.paragraph_format.space_before = Pt(2)
    sp4.paragraph_format.space_after  = Pt(4)
    sr4 = sp4.add_run(f'Admin Status: {gm_status}')
    sr4.bold           = True
    sr4.font.size      = Pt(9)
    sr4.font.color.rgb = GREEN if gm_status == 'Approved' else (RED if gm_status == 'Rejected' else AMBER)

    add_field_table(doc, [
        ('Recipient Name',        val(gm, 'full_name')),
        ('Last Name',             val(gm, 'last_name')),
        ('First Name',            val(gm, 'first_name')),
        ('Middle Name',           val(gm, 'middle_name')),
        ('Issuing School',        val(gm, 'issuing_school')),
        # TODO: [GM_ISSUING_SCHOOL] — verify against school accreditation records
        ('Signatory / Position',  val(gm, 'issuing_authority')),
        ('Date Issued',           val(gm, 'date_issued')),
        ('Purpose',               val(gm, 'purpose')),
        ('Year Graduated / SY',   val(gm, 'year_graduated')),
    ])

    add_ai_verdict_block(doc, gm)

    # ════════════════════════════════════════════════════════
    #  SECTION 5 — DOCUMENT STATUS SUMMARY
    # ════════════════════════════════════════════════════════
    add_section_heading(doc, 'DOCUMENT STATUS SUMMARY', '5.')

    summary_rows = [
        ('PSA Birth Certificate',           statuses.get('BirthCertificate', 'Not Submitted')),
        ('Report Card (Form 138)',           statuses.get('ReportCard',       'Not Submitted')),
        ('Good Moral Certificate',           statuses.get('GoodMoral',        'Not Submitted')),
    ]

    # TODO: Add more required document rows here as your checklist grows.
    # e.g. ('Medical Certificate', statuses.get('MedicalCert', 'Not Submitted'))

    table = doc.add_table(rows=1, cols=3)
    table.style = 'Table Grid'
    table.alignment = WD_TABLE_ALIGNMENT.LEFT

    # Header row
    hdr_cells = table.rows[0].cells
    for i, hdr in enumerate(['Document', 'Admin Status', 'Notes']):
        set_cell_bg(hdr_cells[i], '1a3a8c')
        p  = hdr_cells[i].paragraphs[0]
        r  = p.add_run(hdr)
        r.bold           = True
        r.font.size      = Pt(9)
        r.font.color.rgb = RGBColor(0xff, 0xff, 0xff)

    for doc_name, status in summary_rows:
        row   = table.add_row()
        cells = row.cells
        cells[0].paragraphs[0].add_run(doc_name).font.size = Pt(9)
        sr    = cells[1].paragraphs[0].add_run(status)
        sr.font.size = Pt(9)
        sr.bold      = True
        sr.font.color.rgb = (GREEN if status == 'Approved'
                              else RED if status == 'Rejected'
                              else AMBER)
        # TODO: [NOTES_COL] — add admin remarks per document if needed
        cells[2].paragraphs[0].add_run('').font.size = Pt(9)

    # ════════════════════════════════════════════════════════
    #  FOOTER NOTE
    # ════════════════════════════════════════════════════════
    doc.add_paragraph()
    fn = doc.add_paragraph()
    fn.alignment = WD_ALIGN_PARAGRAPH.CENTER
    fn.paragraph_format.space_before = Pt(16)
    fr = fn.add_run(
        'This document was system-generated by the BCP Enrollment Management System. '
        'All extracted data was analyzed by OpenAI GPT-4o. '
        'Original documents must be presented upon enrollment for manual verification.'
    )
    fr.font.size      = Pt(7.5)
    fr.font.color.rgb = GREY_LIGHT
    fr.italic         = True

    # TODO: Add a signature block here for the Registrar once you have the template.
    # e.g.:
    #   Verified by: ____________________________
    #   Registrar, BCP
    #   Date: __________________

    # ── Save ─────────────────────────────────────────────────
    doc.save(output_path)
    print(f'OK: Document saved to {output_path}')


# ═══════════════════════════════════════════════════════════════
#  ENTRY POINT
# ═══════════════════════════════════════════════════════════════

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print('Usage: python generate_docx.py <payload.json> <output.docx>', file=sys.stderr)
        sys.exit(1)

    json_path   = sys.argv[1]
    output_path = sys.argv[2]

    if not os.path.exists(json_path):
        print(f'ERROR: Payload file not found: {json_path}', file=sys.stderr)
        sys.exit(1)

    with open(json_path, 'r', encoding='utf-8-sig') as f:   # utf-8-sig handles BOM if present
        payload = json.load(f)

    build_document(payload, output_path)
