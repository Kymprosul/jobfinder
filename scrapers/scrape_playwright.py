#!/usr/bin/env python3
"""
Playwright stealth scrapers for Jobfinder blocked sources.
Runs on VPS, posts results to Jobfinder API.

Usage:
    python3 scrape_playwright.py hiredchina
    python3 scrape_playwright.py higheredjobs
    python3 scrape_playwright.py all
"""

import sys
import json
import re
import os
import urllib.request
import urllib.error
from datetime import datetime, timezone

JOBINDER_API = os.environ.get("JOBFINDER_API", "https://jobfinder.kymprosul.com/api/import")


def post_jobs(jobs: list, source: str) -> dict:
    """Post scraped jobs to Jobfinder API."""
    if not jobs:
        print(f"[{source}] No jobs to import")
        return {"imported": 0}

    data = json.dumps(jobs, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        JOBINDER_API,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.loads(resp.read().decode())
            print(f"[{source}] API response: {json.dumps(result, ensure_ascii=False)}")
            return result
    except urllib.error.HTTPError as e:
        body = e.read().decode() if e.fp else ""
        print(f"[{source}] API error {e.code}: {body}")
        return {"error": e.code, "body": body}
    except Exception as e:
        print(f"[{source}] Request error: {e}")
        return {"error": str(e)}


def scrape_hiredchina() -> list:
    """Scrape HiredChina using Playwright stealth + xvfb."""
    from playwright.sync_api import sync_playwright
    from playwright_stealth import Stealth

    stealth = Stealth()
    jobs = []
    keywords = ["spanish", "business", "international business"]

    with sync_playwright() as p:
        browser = p.chromium.launch(
            headless=False,
            args=[
                "--disable-blink-features=AutomationControlled",
                "--no-sandbox",
                "--disable-dev-shm-usage",
            ],
        )
        ctx = browser.new_context(
            viewport={"width": 1366, "height": 900},
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            locale="en-US",
        )
        page = ctx.new_page()
        stealth.apply_stealth_sync(page)

        for kw in keywords:
            print(f"[hiredchina] Scraping keyword: {kw}")
            page.goto(
                f"https://www.hiredchina.com/jobs?kw={kw}",
                wait_until="domcontentloaded",
                timeout=45000,
            )
            page.wait_for_timeout(5000)

            # Scroll to load more
            for _ in range(5):
                page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
                page.wait_for_timeout(1500)

            extracted = page.evaluate(
                """() => {
                const cards = document.querySelectorAll('a._2xUj8ZD7Gp_0_ueX_XD4wu[href*="/jobs/"]');
                const results = [];
                const seen = new Set();

                cards.forEach(card => {
                    const href = card.href;
                    const uuidMatch = href.match(/\\/jobs\\/([a-f0-9-]{20,})$/);
                    if (!uuidMatch || seen.has(uuidMatch[1])) return;
                    seen.add(uuidMatch[1]);

                    const lines = card.innerText.split('\\n').map(l => l.trim()).filter(l => l);
                    if (lines.length < 2) return;

                    let titleLine = lines[0];
                    let location = '';
                    const locMatch = titleLine.match(/\\s*\\[([^\\]]+)\\]\\s*$/);
                    if (locMatch) {
                        location = locMatch[1];
                        titleLine = titleLine.replace(locMatch[0], '').trim();
                    }

                    let salary = '', company = '', category = '', posted = '';
                    for (let i = 1; i < lines.length; i++) {
                        const line = lines[i];
                        if (/\\d+[Kk].*RMB|per month|salary/i.test(line) && !salary) {
                            salary = line;
                        } else if (/Refresh at|hours? ago|days? ago|Posted/i.test(line)) {
                            posted = line;
                        } else if (/^\\d+$/.test(line)) {
                            // view count
                        } else if (!company) {
                            company = line;
                        } else if (!category && /[A-Z]/.test(line)) {
                            category = line;
                        }
                    }

                    results.push({
                        title: titleLine,
                        location: location,
                        company: company,
                        salary: salary,
                        category: category,
                        posted: posted,
                        slug: uuidMatch[1],
                        url: 'https://www.hiredchina.com/jobs/' + uuidMatch[1],
                    });
                });

                return results;
            }"""
            )

            for j in extracted:
                j["search_keyword"] = kw
            jobs.extend(extracted)
            print(f"[hiredchina]   '{kw}': {len(extracted)} jobs")

        browser.close()

    # Deduplicate
    seen = set()
    unique = []
    for j in jobs:
        if j["slug"] not in seen:
            seen.add(j["slug"])
            unique.append(j)

    # Normalize to Jobfinder format
    normalized = []
    now = datetime.now(timezone.utc).isoformat()
    for j in unique:
        # Parse posted date from "Refresh at X hours ago" / "Refresh at X days ago"
        posted_date = None
        if j.get("posted"):
            m = re.search(r"(\d+)\s*(hour|day)", j["posted"], re.I)
            if m:
                from datetime import timedelta

                val = int(m.group(1))
                unit = m.group(2).lower()
                delta = timedelta(hours=val) if "hour" in unit else timedelta(days=val)
                posted_date = (datetime.now(timezone.utc) - delta).strftime("%Y-%m-%d")

        normalized.append(
            {
                "source": "playwright:hiredchina",
                "title": j["title"],
                "institution": j.get("company", ""),
                "location": j.get("location", ""),
                "url": j["url"],
                "description": " | ".join(
                    filter(None, [j.get("salary", ""), j.get("category", ""), j.get("location", "")])
                ),
                "posted_date": posted_date,
                "closing_date": None,
                "category": "international_business",
                "raw_meta": {
                    "hiredchina_slug": j["slug"],
                    "salary": j.get("salary", ""),
                    "category_hc": j.get("category", ""),
                    "search_keyword": j.get("search_keyword", ""),
                    "scraped_at": now,
                    "method": "playwright_stealth",
                },
            }
        )

    print(f"[hiredchina] Total unique: {len(normalized)}")
    return normalized


def scrape_higheredjobs() -> list:
    """Scrape HigherEdJobs using Playwright stealth."""
    from playwright.sync_api import sync_playwright
    from playwright_stealth import Stealth

    stealth = Stealth()
    jobs = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        ctx = browser.new_context(
            viewport={"width": 1366, "height": 900},
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            locale="en-US",
        )
        page = ctx.new_page()
        stealth.apply_stealth_sync(page)

        # Set display to 100 per page
        url = "https://www.higheredjobs.com/international/search.cfm?CountryCode=44"
        print(f"[higheredjobs] Loading {url}")
        page.goto(url, wait_until="domcontentloaded", timeout=45000)
        page.wait_for_timeout(3000)

        # Try to set results per page to 100
        try:
            page.select_option('select[name*="NumResults"], select[id*="display"]', "100", timeout=3000)
            page.wait_for_timeout(3000)
        except Exception:
            pass

        extracted = page.evaluate(
            """() => {
            const results = [];
            const links = document.querySelectorAll('a[href*="details.cfm?JobCode="]');
            const seen = new Set();

            links.forEach(link => {
                const href = link.href;
                const codeMatch = href.match(/JobCode=(\\d+)/);
                if (!codeMatch || seen.has(codeMatch[1])) return;
                seen.add(codeMatch[1]);

                let container = link.closest('.row') || link.closest('div');
                if (!container) return;

                const lines = container.innerText.split('\\n').map(l => l.trim()).filter(l => l);
                if (lines.length < 2) return;

                const title = link.textContent.trim();
                let institution = '', location = '', salary = '', category = '', posted = '';

                for (let i = 1; i < lines.length; i++) {
                    const line = lines[i];
                    if (line === title) continue;
                    if (/Posted\\s+/i.test(line)) {
                        posted = line;
                    } else if (/\\d+.*\\d+.*RMB|salary|\\$|\\d{4,}/i.test(line) && !salary) {
                        salary = line;
                    } else if (!institution && /University|College|Institute|School|Academy/i.test(line)) {
                        institution = line;
                    } else if (!location && /China|Beijing|Shanghai|Guangzhou|Shenzhen|Hangzhou|Chengdu|Wuhan|Nanjing/i.test(line)) {
                        location = line;
                    } else if (!category && /^[A-Z][a-z]/.test(line) && line.length < 60) {
                        category = line;
                    }
                }

                results.push({
                    title: title,
                    jobCode: codeMatch[1],
                    url: href,
                    institution: institution,
                    location: location,
                    salary: salary,
                    category: category,
                    posted: posted,
                    fullText: lines.join(' | ').substring(0, 500),
                });
            });

            return results;
        }"""
        )

        jobs.extend(extracted)
        print(f"[higheredjobs] Found {len(extracted)} jobs")

        # Check for pagination — load more pages if available
        page_links = page.evaluate(
            """() => {
            const links = document.querySelectorAll('a[href*="StartRow="]');
            return links.length;
        }"""
        )
        if page_links > 0:
            print(f"[higheredjobs] Found {page_links} pagination links")

        browser.close()

    # Normalize
    normalized = []
    now = datetime.now(timezone.utc).isoformat()
    for j in jobs:
        posted_date = None
        if j.get("posted"):
            m = re.search(r"Posted\s+(.+)", j["posted"], re.I)
            if m:
                from dateutil import parser as dateparser

                try:
                    posted_date = dateparser.parse(m.group(1), fuzzy=True).strftime("%Y-%m-%d")
                except Exception:
                    pass

        normalized.append(
            {
                "source": "playwright:higheredjobs",
                "title": j["title"],
                "institution": j.get("institution", ""),
                "location": j.get("location", ""),
                "url": j["url"],
                "description": j.get("fullText", j["title"])[:600],
                "posted_date": posted_date,
                "closing_date": None,
                "category": "international_business",
                "raw_meta": {
                    "job_code": j.get("jobCode", ""),
                    "salary": j.get("salary", ""),
                    "category_hej": j.get("category", ""),
                    "scraped_at": now,
                    "method": "playwright_stealth",
                },
            }
        )

    print(f"[higheredjobs] Total: {len(normalized)}")
    return normalized


def main():
    if len(sys.argv) < 2:
        print("Usage: python3 scrape_playwright.py [hiredchina|higheredjobs|all]")
        sys.exit(1)

    target = sys.argv[1].lower()
    all_jobs = []

    if target in ("hiredchina", "all"):
        print("\n=== HiredChina ===")
        jobs = scrape_hiredchina()
        if jobs:
            post_jobs(jobs, "hiredchina")
        all_jobs.extend(jobs)

    if target in ("higheredjobs", "all"):
        print("\n=== HigherEdJobs ===")
        jobs = scrape_higheredjobs()
        if jobs:
            post_jobs(jobs, "higheredjobs")
        all_jobs.extend(jobs)

    print(f"\n=== Total: {len(all_jobs)} jobs imported ===")


if __name__ == "__main__":
    main()
