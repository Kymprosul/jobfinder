import { chromium } from "playwright";

const SEARCH_KEYWORDS = ["spanish", "business"];
const BASE_URL = "https://www.chinateachjobs.com";
const TIMEOUT_MS = 30_000;

function toIsoDate(value) {
  if (!value) return "";
  const text = String(value).trim();
  const direct = text.match(/(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
  if (direct) {
    const y = direct[1];
    const m = direct[2].padStart(2, "0");
    const d = direct[3].padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  const parsed = Date.parse(text);
  if (Number.isNaN(parsed)) return "";
  return new Date(parsed).toISOString().slice(0, 10);
}

function toAbsoluteUrl(url) {
  if (!url) return "";
  try {
    return new URL(url, BASE_URL).toString();
  } catch {
    return "";
  }
}

function cleanText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function extractListingJobs(page) {
  return page.evaluate(() => {
    const clean = (v) => String(v || "").replace(/\s+/g, " ").trim();
    const cards = Array.from(
      document.querySelectorAll(".job-item, .position, .jobs-list li, article, .job")
    );
    const jobs = [];

    for (const card of cards) {
      const anchor =
        card.querySelector('a[href*="/job" i]') ||
        card.querySelector('a[href*="jobs" i]') ||
        card.querySelector("a[href]");

      if (!anchor) continue;

      const title = clean(anchor.textContent);
      const href = anchor.getAttribute("href") || "";
      if (!title || !href) continue;

      const institution =
        clean(card.querySelector(".company, .company-name, .school, .org")?.textContent) || "";
      const location =
        clean(card.querySelector(".location, .city, .job-location")?.textContent) || "";
      const postedDate =
        clean(card.querySelector(".date, .posted, .publish-date, time")?.textContent) || "";
      const snippet =
        clean(card.querySelector(".summary, .desc, .job-desc, .content")?.textContent) || "";

      jobs.push({
        title,
        institution,
        location,
        url: href,
        posted_date: postedDate,
        snippet,
      });
    }

    const seen = new Set();
    return jobs.filter((job) => {
      const key = `${job.title}::${job.url}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  });
}

async function extractDetail(page) {
  return page.evaluate(() => {
    const clean = (v) => String(v || "").replace(/\s+/g, " ").trim();
    const salaryBySelector =
      clean(document.querySelector(".salary, .job-salary, [class*='salary']")?.textContent) || "";

    const blocks = Array.from(
      document.querySelectorAll(".job-description, .description, .detail-content, .job-detail, main, article")
    );

    let description = "";
    for (const block of blocks) {
      const text = clean(block.textContent);
      if (text.length > description.length) {
        description = text;
      }
    }

    let requirements = "";
    const allText = clean(document.body?.textContent || "");
    const reqMatch = allText.match(/requirements?:\s*(.{30,800})/i);
    if (reqMatch) {
      requirements = clean(reqMatch[1]);
    }

    let salary = salaryBySelector;
    if (!salary) {
      const salaryMatch = allText.match(/salary\s*[:：]?\s*([^\n]{2,120})/i);
      if (salaryMatch) {
        salary = clean(salaryMatch[1]);
      }
    }

    return {
      description,
      salary: salary || null,
      requirements,
    };
  });
}

export default async function collect() {
  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== "false",
  });

  const context = await browser.newContext();
  const page = await context.newPage();
  page.setDefaultTimeout(TIMEOUT_MS);

  const results = [];

  try {
    for (const keyword of SEARCH_KEYWORDS) {
      const searchUrl = `${BASE_URL}/jobs/?s=${encodeURIComponent(keyword)}&category=`;

      try {
        await page.goto(searchUrl, { waitUntil: "domcontentloaded", timeout: TIMEOUT_MS });
      } catch {
        continue;
      }

      const listings = await extractListingJobs(page);

      for (const listing of listings) {
        const detailUrl = toAbsoluteUrl(listing.url);
        if (!detailUrl) continue;

        let detailFetched = false;
        let detailDescription = listing.snippet || "";
        let salary = null;
        let requirements = "";

        const detailPage = await context.newPage();
        detailPage.setDefaultTimeout(TIMEOUT_MS);

        try {
          await detailPage.goto(detailUrl, { waitUntil: "domcontentloaded", timeout: TIMEOUT_MS });
          const detail = await extractDetail(detailPage);
          detailFetched = true;
          if (detail.description) {
            detailDescription = detail.description;
          }
          salary = detail.salary;
          requirements = detail.requirements;
        } catch {
          // Ignore single detail failure and continue.
        } finally {
          await detailPage.close();
        }

        results.push({
          source: "chinateachjobs",
          title: cleanText(listing.title),
          institution: cleanText(listing.institution),
          location: cleanText(listing.location),
          url: detailUrl,
          description: cleanText(detailDescription),
          posted_date: toIsoDate(listing.posted_date),
          closing_date: "",
          raw_meta: {
            salary,
            detail_fetched: detailFetched,
            search_keyword: keyword,
            requirements,
          },
        });

        await sleep(1000 + Math.floor(Math.random() * 1000));
      }
    }
  } finally {
    await context.close();
    await browser.close();
  }

  return results;
}
