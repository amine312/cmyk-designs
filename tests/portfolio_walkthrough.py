"""Exercise the quote-to-invoice workflow on a disposable, newly installed shop."""
import os
import secrets
from pathlib import Path

from playwright.sync_api import sync_playwright
from pypdf import PdfReader

BASE = "http://127.0.0.1:8080/"
OUT = Path("docs/demo")
CLIENT = "Atelier Atlas (démo)"
ADMIN = "demo@example.test"


def run(page):
    password = secrets.token_urlsafe(24)
    page.on("dialog", lambda dialog: dialog.accept())
    page.goto(BASE + "install.php")
    for name, value in {"setup_token": os.environ["DEMO_SETUP_TOKEN"], "email": ADMIN,
                        "name": "Atelier démo", "password": password}.items():
        page.locator(f'[name="{name}"]').fill(value)
    page.locator("form button").click()
    assert "Votre atelier est prêt" in page.locator("body").inner_text()
    page.goto(BASE + "index.php")
    page.locator('[name="email"]').fill(ADMIN)
    page.locator('[name="password"]').fill(password)
    page.locator("form button").click()
    page.locator("#main-content").wait_for()

    def form(action):
        return page.locator(f'form:has(input[name="action"][value="{action}"])')

    def submit(action):
        form(action).locator("button").last.click()

    def sheet(amount):
        el = page.locator(".document-sheet")
        assert amount in el.inner_text(), el.inner_text()
        return el

    page.goto(BASE + "index.php?page=settings")
    form("settings_save").locator('[name="company_address"]').fill("Atelier de démonstration, Tanger")
    submit("settings_save")
    page.goto(BASE + "index.php?page=client_form")
    form("client_save").locator('[name="company"]').fill(CLIENT)
    form("client_save").locator('[name="address"]').fill("Studio de démonstration, Tanger")
    submit("client_save")
    page.get_by_text(CLIENT, exact=True).first.wait_for()

    page.goto(BASE + "index.php?page=quote_form")
    draft = form("quote_draft_save")
    draft.locator('[name="client_id"]').select_option(label=CLIENT)
    draft.locator('[name="title"]').fill("Signalétique vitrine")
    draft.locator('[name="category"]').select_option(label="Grand format")
    for key, value in {"designation": "Vinyle imprimé pour vitrine", "quantity": "2.5",
                       "unit_price": "200", "discount_rate": "10", "tax_rate": "20"}.items():
        draft.locator(f'[name="lines[0][{key}]"]').fill(value)
    draft.locator('[name="lines[0][unit]"]').select_option(label="m²")
    submit("quote_draft_save")
    submit("quote_draft_issue")
    sheet("540,00").screenshot(path=OUT / "01-quote.png")
    accept = page.locator('form:has([name="agreement_confirmed"])')
    accept.locator('[name="decision_note"]').fill("Accord simulé pour la démonstration")
    accept.locator('[name="agreement_confirmed"]').check()
    accept.locator("button").click()
    submit("quote_convert")
    invoice = page.locator('form:has(input[name="kind"][value="invoice"])')
    invoice.wait_for()
    page.evaluate("window.scrollTo(0,0)")
    page.screenshot(path=OUT / "02-order.png")
    invoice.locator("button").click()
    sheet("540,00").screenshot(path=OUT / "03-invoice.png")
    invoice_url = page.url
    with page.expect_download() as downloaded:
        page.locator('.document-actions a[href*="download=pdf"]').click()
    invoice_file = OUT / "invoice.pdf"
    downloaded.value.save_as(invoice_file)
    pdf = PdfReader(invoice_file)
    contents = " ".join(p.extract_text() or "" for p in pdf.pages)
    assert "540,00" in contents and "Atelier Atlas" in contents, contents

    page.locator('a[href*="page=credit_form"]').click()
    page.locator("[data-credit-qty]").first.fill("1")
    page.locator('[name="reason"]').fill("Retour simulé de 1 m² de vinyle")
    page.locator('[name="issue_confirmed"]').check()
    submit("credit_issue")
    sheet("216,00").screenshot(path=OUT / "04-credit.png")
    page.goto(invoice_url)
    assert "324,00" in page.locator("body").inner_text()
    print("Verified quote, converted order, downloaded invoice PDF, partial credit and remaining balance.")


if __name__ == "__main__":
    OUT.mkdir(exist_ok=True, parents=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="chrome", args=["--no-sandbox"])
        page = browser.new_page(viewport={"width": 1440, "height": 960}, locale="fr-FR")
        try:
            run(page)
        except Exception:
            print("Failed at", page.url, page.locator(".notice.error").all_text_contents())
            raise
        finally:
            browser.close()
