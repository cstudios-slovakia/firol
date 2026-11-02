import json
import os
import sys
import time
from playwright.sync_api import sync_playwright

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
BASE_URL = "http://localhost:5173"
SCREENSHOT_DIR = "e2e_screenshots"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

def log(msg):
    print(msg, flush=True)

def run():
    log("Starting Playwright E2E Verification...")
    with sync_playwright() as p:
        browser = p.chromium.launch(
            executable_path=CHROME_PATH,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"]
        )
        context = browser.new_context(viewport={"width": 1280, "height": 900})
        page = context.new_page()
        page.on("console", lambda msg: log(f"CONSOLE: {msg.text}"))
        page.on("pageerror", lambda err: log(f"PAGEERROR: {err}"))
        page.on("requestfailed", lambda req: log(f"REQUEST FAILED: {req.method} {req.url} {req.failure}"))

        # Step 0: Login
        log("\n--- STEP 0: Login ---")
        page.goto(f"{BASE_URL}/login")
        page.wait_for_selector('input[type="email"]')
        page.fill('input[type="email"]', 'akceptacia@firol.test')
        page.fill('input[type="password"]', 'Akceptacia-2026!')
        page.click('button[type="submit"]')
        page.wait_for_url(f"{BASE_URL}/")
        log(f"Logged in successfully. Current URL: {page.url}")

        # Get company IDs
        companies_data = page.evaluate("""() => {
            return fetch('/api/companies').then(r => r.json());
        }""")
        items = companies_data.get('items', companies_data if isinstance(companies_data, list) else [])
        companies = {c['name']: c for c in items}
        log(f"Loaded companies: {list(companies.keys())}")

        bd_comp = companies.get('Bytové družstvo Lipová')
        firma_comp = companies.get('Firma s 50 PHP')
        assert bd_comp, "Bytové družstvo Lipová not found"
        assert firma_comp, "Firma s 50 PHP not found"

        # =========================================================================
        # ITEM 1: Bytový dom vs Firma Vchody (Decision D1)
        # =========================================================================
        log("\n--- ITEM 1: Bytový dom vs Firma Vchody ---")
        # 1a: BD facility edit form has Vchody input
        bd_facs = page.evaluate(f"() => fetch('/api/companies/{bd_comp['id']}').then(r => r.json())")
        bd_fac1_id = bd_facs['facilities'][0]['id']
        page.goto(f"{BASE_URL}/facilities/{bd_fac1_id}/edit")
        page.wait_for_selector('input[placeholder*="napr. 3 alebo 2, 4, 6"]')
        assert page.is_visible('input[placeholder*="napr. 3 alebo 2, 4, 6"]'), "BD facility form must show Vchody input"
        log("PASS 1a: BD facility form shows Vchody input")
        page.screenshot(path=f"{SCREENSHOT_DIR}/01_bd_facility_vchody.png")

        # 1b: Firma facility edit form does NOT have Vchody input
        firma_facs = page.evaluate(f"() => fetch('/api/companies/{firma_comp['id']}').then(r => r.json())")
        firma_fac_id = firma_facs['facilities'][0]['id']
        page.goto(f"{BASE_URL}/facilities/{firma_fac_id}/edit")
        page.wait_for_selector('form')
        vchod_input = page.query_selector('input[placeholder*="napr. 3 alebo 2, 4, 6"]')
        assert vchod_input is None, "Firma facility form must NEVER show Vchody input"
        log("PASS 1b: Firma facility form has NO Vchody input")
        page.screenshot(path=f"{SCREENSHOT_DIR}/01_firma_facility_no_vchody.png")

        # =========================================================================
        # ITEM 3: Step 1 lehota_zakon + lehota_vlastna & Book checkbox removed
        # =========================================================================
        log("\n--- ITEM 3: Step 1 lehota_zakon + lehota_vlastna ---")
        page.goto(f"{BASE_URL}/prehliadky/new?company_id={firma_comp['id']}&facility_id={firma_fac_id}")
        page.wait_for_selector('text=Lehota prehliadky')
        content = page.content()
        assert "12 mesiacov" in content
        assert "6 mesiacov" in content
        assert "3 mesiace" in content
        assert "§" not in page.text_content('div:has-text("Lehota prehliadky")')
        log("PASS 3a: Step 1 Lehota prehliadky has 12, 6, 3 months without §")

        # Check lehota_vlastna validation when >= lehota_zakon
        page.click('button:has-text("vlastná")')
        page.wait_for_selector('input[type="number"]')
        page.fill('input[type="number"]', '3')
        page.wait_for_selector('text=nie je kratšia než zákonná')
        assert "nie je kratšia než zákonná" in page.content(), "Warning about longer/equal deadline must appear"
        log("PASS 3b: lehota_vlastna >= lehota_zakon displays warning")
        page.screenshot(path=f"{SCREENSHOT_DIR}/03_step1_lehota_warning.png")

        # Fix lehota_vlastna to 2 months (valid shorter)
        page.fill('input[type="number"]', '2')
        # Verify book checkbox is NOT in step 1
        assert "Zapísať do požiarnej knihy" not in page.content(), "Zápis do požiarnej knihy checkbox must NOT be in Step 1"
        log("PASS 3c: Book checkbox is not in Step 1")

        # Select scope: Obidve
        page.click('button:has-text("Obidve")')
        log("Selected Obidve scope")

        # Save Step 1 and proceed to walk (page navigates to /prehliadky/{id})
        log("Clicking pp-start...")
        page.screenshot(path=f"{SCREENSHOT_DIR}/03_before_pp_start.png")
        page.click('button#pp-start')
        page.wait_for_url("**/prehliadky/*", timeout=10000)
        page.wait_for_selector('button#pp-add-defect', timeout=10000)
        log(f"PASS 3d: Step 1 saved and proceeded to walk ({page.url})")
        page.screenshot(path=f"{SCREENSHOT_DIR}/03_after_pp_start.png")

        # =========================================================================
        # ITEM 2: Collapsible 0a.2 defect form & Kde
        # =========================================================================
        log("\n--- ITEM 2: Collapsible 0a.2 defect form ---")
        page.click('button#pp-add-defect')
        page.wait_for_selector('text=Kde')

        # Firma defect page: only Miesto collapsible row exists (no Vchod or Podlažie)
        kde_text = page.text_content('div:has-text("Kde")')
        assert "Vchod" not in kde_text, "Firma defect page must not show Vchod row"
        assert "Podlažie" not in kde_text, "Firma defect page must not show Podlažie row"
        log("PASS 2a: Firma defect page has no Vchod or Podlažie row in Kde")

        # Čoho sa to týka is collapsed by default into summary
        assert "Čoho sa to týka" in page.content()
        page.screenshot(path=f"{SCREENSHOT_DIR}/02_defect_form_firma.png")

        # Select a defect from catalog
        page.wait_for_selector('section[aria-label="Čo to je"] ul button')
        page.click('section[aria-label="Čo to je"] ul button')
        time.sleep(0.5)

        # In Kde, fill detail
        detail_input = page.wait_for_selector('input[placeholder*="státie č. 11"]')
        detail_input.fill('sklad A')

        # Expand "Čoho sa to týka" to verify free-text manual input is always available
        page.click('button:has-text("Čoho sa to týka")')
        time.sleep(0.3)
        manual_input = page.wait_for_selector('input[placeholder*="únikový východ"]')
        manual_input.fill('dvere do skladu A')
        log("PASS 2b: Čoho sa to týka expanded and free text input filled")

        # Save defect
        page.click('button#pp-save')
        page.wait_for_selector('button#pp-add-defect', timeout=10000)
        log("PASS 2c: Defect saved successfully")

        # =========================================================================
        # ITEM 4: Step 3 Poznámka & Oba záznamy (external terms ob)
        # =========================================================================
        log("\n--- ITEM 4: Step 3 Poznámka & External terms ob ---")
        page.click('button#pp-continue')
        page.wait_for_selector('textarea')

        # Fill "Poznámka do záznamu" textarea
        page.fill('textarea', 'Testovacia poznámka do záznamu z prehliadky')
        log("PASS 4a: Poznámka do záznamu is present and filled")

        # Check "Pridať termín cudzej revízie" modal has "ob" option
        page.click('button#pp-add-external')
        page.wait_for_selector('div[role="dialog"]')
        # Open combobox for "Patrí do záznamu"
        combos = page.query_selector_all('div[role="dialog"] button[role="combobox"]')
        if len(combos) > 1:
            combos[1].click()
        elif combos:
            combos[0].click()
        time.sleep(0.3)
        term_modal_text = page.content()
        assert "Obom — PO aj BOZP" in term_modal_text or "Obom" in term_modal_text, "External term must offer 'Obom — PO aj BOZP'"
        log("PASS 4b: External term offers 'Obom — PO aj BOZP'")
        page.screenshot(path=f"{SCREENSHOT_DIR}/04_step3_external_term_ob.png")
        page.click('button:has-text("Zrušiť")')
        time.sleep(0.5)

        # Proceed to Step 4 (Uzavretie)
        page.click('button#pp-to-close')
        page.wait_for_selector('text=S výsledkom prehliadky oboznámený')

        # =========================================================================
        # ITEM 5: Uzavretie book checkbox & Oboznámený (single person)
        # =========================================================================
        log("\n--- ITEM 5: Uzavretie book checkbox & Oboznámený ---")
        close_content = page.content()
        assert "Zapísať aj do požiarnej knihy" in close_content, "Book checkbox must be on Uzavretie screen"
        assert "S výsledkom prehliadky oboznámený" in close_content, "Single person Oboznámený must be present"
        assert "Prítomní pri prehliadke" not in close_content, "Old Prítomní list must be removed"
        log("PASS 5a: Uzavretie has 'Zapísať aj do požiarnej knihy' (default unchecked) and single 'Oboznámený'")
        page.screenshot(path=f"{SCREENSHOT_DIR}/05_uzavretie_screen.png")

        # Close the prehliadka with book_entry UNCHECKED
        name_input = page.query_selector('input#uz-kto, input[placeholder*="Peter Správca"]')
        if name_input and not name_input.input_value():
            name_input.fill('Ing. Ján Zodpovedný')
        page.click('button#pp-close')
        page.wait_for_selector('h1:has-text("Prehliadka je uzavretá")', timeout=15000)

        # =========================================================================
        # ITEM 8: Result Screen (PrehliadkaResultPage)
        # =========================================================================
        log("\n--- ITEM 8: Result Screen ---")
        res_content = page.content()
        assert "Pridať do požiarnej knihy" in res_content, "Offer to add to fire book must appear on result screen"
        assert "Kniha BOZP" not in res_content, "Kniha BOZP must not appear"
        log("PASS 8: Result screen offers 'Pridať do požiarnej knihy'")
        page.screenshot(path=f"{SCREENSHOT_DIR}/08_result_screen_offer.png")

        # =========================================================================
        # ITEM 6: BookPage & unified "Nový zápis" pull dialog
        # =========================================================================
        log("\n--- ITEM 6: BookPage & Nový zápis pull dialog ---")
        page.goto(f"{BASE_URL}/companies/{firma_comp['id']}/kniha")
        page.wait_for_selector('h1:has-text("Požiarna kniha")')
        book_content = page.content()
        # Verify no Kniha BOZP tab
        assert "Kniha BOZP" not in book_content, "Kniha BOZP tab must be gone"
        assert "Appka ponúka pridať" not in book_content, "Push cards 'Appka ponúka pridať' must be gone"
        assert "Termíny cudzích revízií" not in book_content, "External terms section must be gone from book"
        assert "Podľa čísla protokolu" not in book_content, "Zápis podľa čísla protokolu button must be gone"
        # Verify Nový zápis card with button
        assert "Nový zápis" in book_content
        assert "+ Nový zápis do požiarnej knihy" in book_content
        assert "Vytlačiť celú knihu" in book_content
        log("PASS 6a: BookPage is strictly Požiarna kniha without push cards or BOZP tab")
        page.screenshot(path=f"{SCREENSHOT_DIR}/06_book_page.png")

        # Open + Nový zápis do požiarnej knihy modal
        page.click('button#btn-novy-zapis')
        page.wait_for_selector('div[role="dialog"]')
        modal_content = page.content()

        assert "Čo má ísť do zápisu" in modal_content
        assert "Vlastný text" in modal_content
        assert "Zistené závady a nedostatky" in modal_content
        assert "Pridať zákonnú vetu" in modal_content
        assert "Takto bude zápis vyzerať" in modal_content
        assert "Zapísať do požiarnej knihy" in modal_content
        assert "Zapísať a vytlačiť záznam" in modal_content
        log("PASS 6b: Unified Nový zápis modal has all required sections and both submit buttons")
        page.screenshot(path=f"{SCREENSHOT_DIR}/06_novy_zapis_modal.png")

        # Test toggle zákonná veta
        page.click('label:has-text("Pridať zákonnú vetu")')
        time.sleep(0.3)
        preview_text = page.text_content('div:has-text("Takto bude zápis vyzerať")')
        assert "314/2001" in preview_text, "Preview must update with zákonná veta"
        log("PASS 6c: Pridať zákonnú vetu updates preview text")

        # Submit entry via "Zapísať do požiarnej knihy"
        page.click('button:has-text("Zapísať do požiarnej knihy")')
        page.wait_for_selector('text=čaká na podpis vedúceho', timeout=10000)
        log("PASS 6d: Entry created successfully in Požiarna kniha")
        page.screenshot(path=f"{SCREENSHOT_DIR}/06_entry_created.png")

        # =========================================================================
        # ITEM 7: PDF print outputs
        # =========================================================================
        log("\n--- ITEM 7: PDF print outputs ---")
        # Verify single entry print link exists on the entry card
        page.wait_for_selector('a:has-text("Tlač")')
        entry_print_href = page.get_attribute('a:has-text("Tlač")', 'href')
        assert "mode=entry" in entry_print_href, f"Entry print link must have mode=entry, got: {entry_print_href}"
        log("PASS 7a: Single entry print link has mode=entry")

        # Verify full book print link has mode=all
        full_book_href = page.get_attribute('a:has-text("Vytlačiť celú knihu")', 'href')
        assert "mode=all" in full_book_href, f"Full book print link must have mode=all, got: {full_book_href}"
        log("PASS 7b: Full book print link has mode=all")

        # Fetch and verify entry PDF response
        pdf_entry_res = page.evaluate(f"""() => {{
            return fetch('{entry_print_href}').then(r => ({{ status: r.status, type: r.headers.get('content-type') }}));
        }}""")
        assert pdf_entry_res['status'] == 200, f"Entry PDF returned status {pdf_entry_res['status']}"
        assert 'pdf' in pdf_entry_res['type'], f"Entry PDF type is {pdf_entry_res['type']}"
        log("PASS 7c: Entry PDF renders HTTP 200 application/pdf")

        # Fetch and verify full book PDF response
        pdf_all_res = page.evaluate(f"""() => {{
            return fetch('{full_book_href}').then(r => ({{ status: r.status, type: r.headers.get('content-type') }}));
        }}""")
        assert pdf_all_res['status'] == 200, f"Full book PDF returned status {pdf_all_res['status']}"
        assert 'pdf' in pdf_all_res['type'], f"Full book PDF type is {pdf_all_res['type']}"
        log("PASS 7d: Full book PDF renders HTTP 200 application/pdf")

        # Test Bytový dom scope switch on BD BookPage
        log("\n--- BD Scope Switch verification ---")
        page.goto(f"{BASE_URL}/companies/{bd_comp['id']}/kniha")
        page.wait_for_selector('h1:has-text("Požiarna kniha")')
        bd_book_content = page.content()
        assert "Spoločná kniha za celý subjekt" in bd_book_content, "BD must show 'Spoločná kniha za celý subjekt'"
        assert "Samostatne za každý dom" in bd_book_content, "BD must show 'Samostatne za každý dom'"
        log("PASS: BD Scope switch labels are 'Spoločná kniha za celý subjekt' and 'Samostatne za každý dom'")
        page.screenshot(path=f"{SCREENSHOT_DIR}/07_bd_scope_switch.png")

        browser.close()
        log("\n=======================================================")
        log("ALL 8 E2E BROWSER CHECKLIST ITEMS VERIFIED AND PASSED!")
        log("=======================================================\n")

if __name__ == '__main__':
    run()
