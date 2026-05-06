import { chromium } from "playwright";

const SEARCH_KEYWORDS = ["spanish", "business"];
const BASE_URL = "https://www.hiredchina.com";
const TIMEOUT_MS = 30_000;

function cleanText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

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

function parseCookieHeader(cookieHeader) {
  return cookieHeader
    .split(";")
    .map((part) => part.trim())
    .filter(Boolean)
    .map((pair) => {
      const idx = pair.indexOf("=");
      if (idx <= 0) return null;
      const name = pair.slice(0, idx).trim();
      const value = pair.slice(idx + 1).trim();
      if (!name) return null;
      return {
        name,
        value,
        domain: ".hiredchina.com",
        path: "/",
      };
    })
    .filter(Boolean);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function mapApiItemToJob(item, keyword) {
  if (!item || typeof item !== "object") return null;

  const title = cleanText(item.title || item.jobTitle || item.positionName);
  const institution = cleanText(item.company || item.companyName || item.employer || "");
  const location = cleanText(item.city || item.location || item.workCity || "");
  const url = toAbsoluteUrl(item.url || item.detailUrl || item.link || "");

  if (!title || !url) return null;

  return {
    source: "hiredchina",
    title,
    institution,
    location,
    url,
    description: cleanText(item.description || item.jobDescription || item.summary || ""),
    posted_date: toIsoDate(item.postedDate || item.publishDate || item.createdAt || ""),
    closing_date: toIsoDate(item.closingDate || item.deadline || ""),
    raw_meta: {
      salary: cleanText(item.salary || item.salaryRange || "") || null,
      detail_fetched: false,
      search_keyword: keyword,
      from_api: true,
    },
  };
}

async function extractListingJobs(page) {
  return page.evaluate(() => {
    const clean = (v) => String(v || "").replace(/\s+/g, " ").trim();
    const cards = Array.from(
      document.querySelectorAll(".job-item, .position-item, .search-result-item, li, article")
    );
    const jobs = [];

    for (const card of cards) {
      const anchor =
        card.querySelector('a[href*="job" i]') ||
        card.querySelector('a[href*="position" i]') ||
        card.querySelector("a[href]");

      if (!anchor) continue;
      const title = clean(anchor.textContent);
      const href = anchor.getAttribute("href") || "";
      if (!title || !href) continue;

      const institution =
        clean(card.querySelector(".company, .company-name, .employer")?.textContent) || "";
      const location = clean(card.querySelector(".location, .city")?.textContent) || "";
      const snippet = clean(card.querySelector(".summary, .desc, .job-desc")?.textContent) || "";
      const postedDate = clean(card.querySelector(".date, .posted, time")?.textContent) || "";

      jobs.push({ title, institution, location, url: href, snippet, posted_date: postedDate });
    }

    return jobs;
  });
}

async function extractDetail(page) {
  return page.evaluate(() => {
    const clean = (v) => String(v || "").replace(/\s+/g, " ").trim();
    const salary =
      clean(document.querySelector(".salary, .job-salary, [class*='salary']")?.textContent) || null;
    const description =
      clean(
        document.querySelector(".job-description, .description, .detail-content, .job-detail, main, article")
          ?.textContent
      ) || "";
    return { salary, description };
  });
}

export default async function collect() {
  const cookieHeader = process.env.HIREDCHINA_COOKIE;
  if (!cookieHeader) {
    console.warn("[hiredchina] HIREDCHINA_COOKIE is not set. Skipping.");
    return [];
  }

  const browser = await chromium.launch({
    headless: process.env.PLAYWRIGHT_HEADLESS !== "false",
  });

  const context = await browser.newContext();
  const cookies = parseCookieHeader(cookieHeader);
  if (cookies.length > 0) {
    await context.addCookies(cookies);
  }

  const page = await context.newPage();
  page.setDefaultTimeout(TIMEOUT_MS);

  const results = [];
  const apiCollected = [];
  let activeKeyword = "";

  page.on("response", async (response) => {
    try {
      const url = response.url();
      const contentType = response.headers()["content-type"] || "";
      if (!contentType.includes("application/json")) return;
      if (!/job|position|search/i.test(url)) return;

      const body = await response.json();
      const candidates = [];

      if (Array.isArray(body)) {
        candidates.push(...body);
      } else if (body && typeof body === "object") {
        for (const key of ["data", "list", "items", "results", "rows"]) {
          if (Array.isArray(body[key])) {
            candidates.push(...body[key]);
          }
        }
      }

      for (const item of candidates) {
        const job = mapApiItemToJob(item, activeKeyword);
        if (job) apiCollected.push(job);
      }
    } catch {
      // Ignore response parsing errors.
    }
  });

  try {
    for (const keyword of SEARCH_KEYWORDS) {
      activeKeyword = keyword;
      const urls = [
        `${BASE_URL}/?keyword=${encodeURIComponent(keyword)}`,
        `${BASE_URL}/search?keyword=${encodeURIComponent(keyword)}`,
      ];

      let opened = false;
      for (const url of urls) {
        try {
          await page.goto(url, { waitUntil: "domcontentloaded", timeout: TIMEOUT_MS });
          opened = true;
          break;
        } catch {
          // Try next URL shape.
        }
      }

      if (!opened) {
        continue;
      }

      await sleep(1500);

      const listings = await extractListingJobs(page);

      for (const listing of listings) {
        const detailUrl = toAbsoluteUrl(listing.url);
        if (!detailUrl) continue;

        let detailFetched = false;
        let description = cleanText(listing.snippet);
        let salary = null;

        const detailPage = await context.newPage();
        detailPage.setDefaultTimeout(TIMEOUT_MS);

        try {
          await detailPage.goto(detailUrl, { waitUntil: "domcontentloaded", timeout: TIMEOUT_MS });
          const detail = await extractDetail(detailPage);
          detailFetched = true;
          description = cleanText(detail.description || description);
          salary = detail.salary;
        } catch {
          // Keep partial listing data only.
        } finally {
          await detailPage.close();
        }

        results.push({
          source: "hiredchina",
          title: cleanText(listing.title),
          institution: cleanText(listing.institution),
          location: cleanText(listing.location),
          url: detailUrl,
          description,
          posted_date: toIsoDate(listing.posted_date),
          closing_date: "",
          raw_meta: {
            salary,
            detail_fetched: detailFetched,
            search_keyword: keyword,
          },
        });

        await sleep(1000 + Math.floor(Math.random() * 1000));
      }
    }
  } catch {
    // Return partial data on any block/failure.
  } finally {
    await context.close();
    await browser.close();
  }

  const merged = [...results, ...apiCollected];
  const seen = new Set();
  return merged.filter((job) => {
    const key = job.url;
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}
