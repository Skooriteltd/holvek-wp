/**
 * Holvek SEO Score Calculator
 * 
 * Calculates a composite 0-100 SEO score from keyword, meta, and readability checks.
 * Each check contributes weighted points to the total.
 */

/**
 * Calculate the aggregate SEO score.
 * 
 * @param {object} params
 * @param {string} params.title - Post title
 * @param {string} params.content - Raw HTML content
 * @param {string} params.focusKeyword - Focus keyword
 * @param {string} params.metaTitle - Meta title override
 * @param {string} params.metaDescription - Meta description
 * @param {object} params.readability - Readability analysis results
 * @returns {{ score: number, color: string, label: string, checks: Array }}
 */
export function calculateSeoScore({ title, content, focusKeyword, metaTitle, metaDescription, readability, isCornerstone = false }) {
    const checks = [];
    let earned = 0;
    let total = 0;
    const keywordGaps = [];

    const plainContent = content
        .replace(/<!--.*?-->/gs, ' ')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const wordCount = (plainContent.match(/\b\w+\b/g) || []).length;
    const effectiveTitle = metaTitle || title;
    const lowerContent = plainContent.toLowerCase();
    const lowerTitle = title.toLowerCase();
    const lowerKeyword = focusKeyword ? focusKeyword.toLowerCase() : '';

    // ── Cornerstone Flag Check (Info) ──────────────────────────
    if (isCornerstone) {
        checks.push({ label: 'Marked as Cornerstone Content (High standards)', pass: true, weight: 0 });
    }

    // ── Keyword Checks (60 points total max keyword weight) ──────────────────────────
    if (focusKeyword) {
        // Keyword in title (10 pts)
        const kwInTitle = lowerTitle.includes(lowerKeyword);
        checks.push({ label: 'Keyword in title', pass: kwInTitle, weight: 10 });
        if (kwInTitle) earned += 10;
        total += 10;
        if (!kwInTitle) keywordGaps.push("Missing in Post Title");

        // Keyword in first paragraph (8 pts)
        const firstPara = lowerContent.substring(0, 300);
        const kwInIntro = firstPara.includes(lowerKeyword);
        checks.push({ label: 'Keyword in introduction', pass: kwInIntro, weight: 8 });
        if (kwInIntro) earned += 8;
        total += 8;
        if (!kwInIntro) keywordGaps.push("Missing in Introduction paragraph");

        // Keyword in content (7 pts)
        const kwInContent = lowerContent.includes(lowerKeyword);
        checks.push({ label: 'Keyword in content', pass: kwInContent, weight: 7 });
        if (kwInContent) earned += 7;
        total += 7;
        if (!kwInContent) keywordGaps.push("Missing in body content");

        // Advanced Scans: Headings, Paragraphs, Image ALTs
        let hasSubheadings = false;
        let kwInSubheading = false;
        let kwInParagraphs = false;
        let kwInAlt = false;
        let hasImages = false;

        if (typeof DOMParser !== 'undefined' && content) {
            try {
                const parser = new DOMParser();
                const doc = parser.parseFromString(content, 'text/html');
                
                // Subheadings: H2, H3, H4, H5, H6
                const subheadingNodes = Array.from(doc.querySelectorAll('h2, h3, h4, h5, h6'));
                hasSubheadings = subheadingNodes.length > 0;
                const subheadingsText = subheadingNodes.map(h => h.textContent.toLowerCase());
                kwInSubheading = subheadingsText.some(text => text.includes(lowerKeyword));
                
                // Body paragraphs: p
                const paragraphNodes = Array.from(doc.querySelectorAll('p'));
                const paragraphsText = paragraphNodes.map(p => p.textContent.toLowerCase());
                kwInParagraphs = paragraphsText.some(text => text.includes(lowerKeyword));
                
                // Image alt tags
                const imgNodes = Array.from(doc.querySelectorAll('img'));
                hasImages = imgNodes.length > 0;
                const altTexts = imgNodes.map(img => (img.getAttribute('alt') || '').toLowerCase());
                kwInAlt = altTexts.some(text => text.includes(lowerKeyword));
            } catch (e) {
                console.error('Holvek SEO DOMParser error, using fallback:', e);
            }
        } else {
            // Regex Fallbacks
            const headingMatches = content.match(/<h[2-6][^>]*>([\s\S]*?)<\/h[2-6]>/gi) || [];
            hasSubheadings = headingMatches.length > 0;
            kwInSubheading = headingMatches.some(h => h.toLowerCase().includes(lowerKeyword));

            const paraMatches = content.match(/<p[^>]*>([\s\S]*?)<\/p>/gi) || [];
            kwInParagraphs = paraMatches.some(p => p.toLowerCase().includes(lowerKeyword));

            const imgMatches = content.match(/<img[^>]*>/gi) || [];
            hasImages = imgMatches.length > 0;
            kwInAlt = imgMatches.some(img => {
                const altMatch = img.match(/alt=["']([^"']*)["']/i);
                return altMatch && altMatch[1].toLowerCase().includes(lowerKeyword);
            });
        }

        // Keyword in Subheading (6 pts)
        checks.push({ label: 'Keyword in subheading (H2-H6)', pass: kwInSubheading, weight: 6 });
        if (kwInSubheading) earned += 6;
        total += 6;
        if (hasSubheadings) {
            if (!kwInSubheading) keywordGaps.push("Missing in subheadings (H2, H3, etc.)");
        } else {
            keywordGaps.push("No subheadings (H2-H6) found in content");
        }

        // Keyword in Paragraphs (5 pts)
        checks.push({ label: 'Keyword in body paragraphs', pass: kwInParagraphs, weight: 5 });
        if (kwInParagraphs) earned += 5;
        total += 5;
        if (!kwInParagraphs) keywordGaps.push("Missing in body paragraphs");

        // Keyword in Image ALTs (5 pts)
        const altLabel = hasImages ? 'Keyword in image ALT attributes' : 'Keyword in image ALT attributes (No images found)';
        checks.push({ label: altLabel, pass: kwInAlt, weight: 5 });
        if (kwInAlt) earned += 5;
        total += 5;
        if (hasImages) {
            if (!kwInAlt) keywordGaps.push("Missing in image ALT attributes");
        } else {
            keywordGaps.push("No images found to check ALT attributes");
        }

        // Keyword density 0.5-2.5% (8 pts)
        const kwOccurrences = (lowerContent.split(lowerKeyword).length - 1);
        const density = wordCount > 0 ? (kwOccurrences / wordCount) * 100 : 0;
        const densityOk = density >= 0.5 && density <= 2.5;
        checks.push({ label: `Keyword density: ${density.toFixed(1)}% (0.5-2.5%)`, pass: densityOk, weight: 8 });
        if (densityOk) earned += 8;
        total += 8;

        // Keyword in meta title (7 pts)
        const kwInMetaTitle = effectiveTitle.toLowerCase().includes(lowerKeyword);
        checks.push({ label: 'Keyword in meta title', pass: kwInMetaTitle, weight: 7 });
        if (kwInMetaTitle) earned += 7;
        total += 7;
        if (!kwInMetaTitle) keywordGaps.push("Missing in meta title");
    } else {
        // No keyword set — assign 0 for all keyword checks
        checks.push({ label: 'Focus keyword is set', pass: false, weight: 56 });
        total += 56;
        keywordGaps.push("No focus keyword defined yet.");
    }

    // ── Meta Checks (25 points) ──────────────────────────
    const titleLen = effectiveTitle.length;
    const titleOk = titleLen > 0 && titleLen <= 60;
    checks.push({ label: `Meta title length: ${titleLen} chars (≤60)`, pass: titleOk, weight: 8 });
    if (titleOk) earned += 8;
    total += 8;

    const descLen = (metaDescription || '').length;
    const descOk = descLen >= 120 && descLen <= 160;
    checks.push({ label: `Meta description: ${descLen} chars (120-160)`, pass: descOk, weight: 9 });
    if (descOk) earned += 9;
    total += 9;

    // Cornerstone has strict content length requirement
    const minWords = isCornerstone ? 1000 : 300;
    const lengthOk = wordCount >= minWords;
    checks.push({ label: `Content length: ${wordCount} words (target ≥${minWords})`, pass: lengthOk, weight: 8 });
    if (lengthOk) earned += 8;
    total += 8;

    // Cornerstone has mandatory internal links check
    if (isCornerstone) {
        const linkCount = (content.match(/<a\s+[^>]*href=/g) || []).length;
        const linksOk = linkCount >= 3;
        checks.push({ label: `Internal link density: ${linkCount} links (target ≥3)`, pass: linksOk, weight: 5 });
        if (linksOk) earned += 5;
        total += 5;
    }

    // ── Readability Checks (35 points) ──────────────────────────
    if (readability) {
        checks.push({ label: `Flesch score: ${readability.flesch.score} (${readability.flesch.label})`, pass: readability.flesch.pass, weight: 10 });
        if (readability.flesch.pass) earned += 10;
        total += 10;

        checks.push({ label: `Long sentences: ${readability.longSentences.value}% (≤25%)`, pass: readability.longSentences.pass, weight: 7 });
        if (readability.longSentences.pass) earned += 7;
        total += 7;

        checks.push({ label: `Passive voice: ${readability.passiveVoice.value}% (≤10%)`, pass: readability.passiveVoice.pass, weight: 6 });
        if (readability.passiveVoice.pass) earned += 6;
        total += 6;

        checks.push({ label: `Transition words: ${readability.transitionWords.value}% (≥30%)`, pass: readability.transitionWords.pass, weight: 6 });
        if (readability.transitionWords.pass) earned += 6;
        total += 6;

        checks.push({ label: 'Subheading distribution', pass: readability.subheadings.pass, weight: 6 });
        if (readability.subheadings.pass) earned += 6;
        total += 6;
    }

    const score = total > 0 ? Math.round((earned / total) * 100) : 0;

    return {
        score,
        color: score >= 80 ? '#22c55e' : score >= 50 ? '#f59e0b' : '#ef4444',
        label: score >= 80 ? 'Good' : score >= 50 ? 'Needs Work' : 'Poor',
        checks,
        keywordGaps,
    };
}
