/**
 * Holvek Readability Analysis Engine
 * 
 * Implements Flesch Reading Ease, sentence analysis, passive voice detection,
 * transition word checks, and subheading distribution analysis.
 */

const TRANSITION_WORDS = [
    'accordingly', 'additionally', 'afterward', 'albeit', 'also', 'although',
    'as a result', 'because', 'besides', 'but', 'certainly', 'comparatively',
    'consequently', 'conversely', 'equally', 'eventually', 'finally',
    'for example', 'for instance', 'further', 'furthermore', 'hence',
    'however', 'in addition', 'in conclusion', 'in contrast', 'in fact',
    'in other words', 'in particular', 'in short', 'in summary', 'indeed',
    'instead', 'likewise', 'meanwhile', 'moreover', 'nevertheless',
    'nonetheless', 'notably', 'on the contrary', 'on the other hand',
    'otherwise', 'overall', 'particularly', 'rather', 'regardless',
    'similarly', 'specifically', 'still', 'subsequently', 'such as',
    'that is', 'therefore', 'though', 'thus', 'to summarize',
    'ultimately', 'undoubtedly', 'whereas', 'while', 'yet'
];

const PASSIVE_AUXILIARIES = ['am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'get', 'gets', 'got', 'gotten', 'getting'];

/**
 * Strip HTML tags and Gutenberg block comments from content.
 */
export function stripHtml(html) {
    return html
        .replace(/<!--.*?-->/gs, ' ')      // Remove HTML/Gutenberg comments
        .replace(/<[^>]*>/g, ' ')           // Remove HTML tags
        .replace(/&nbsp;/g, ' ')            // Replace non-breaking spaces
        .replace(/\s+/g, ' ')              // Collapse whitespace
        .trim();
}

/**
 * Split text into sentences using common delimiters.
 */
export function getSentences(text) {
    return text
        .split(/[.!?]+/)
        .map(s => s.trim())
        .filter(s => s.length > 0);
}

/**
 * Split text into paragraphs.
 */
export function getParagraphs(html) {
    // Split on paragraph tags or double newlines
    return html
        .split(/<\/p>|<br\s*\/?>\s*<br\s*\/?>|\n\n/i)
        .map(p => stripHtml(p).trim())
        .filter(p => p.length > 0);
}

/**
 * Count syllables in a word (English approximation).
 */
function countSyllables(word) {
    word = word.toLowerCase().replace(/[^a-z]/g, '');
    if (word.length <= 3) return 1;
    
    word = word.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '');
    word = word.replace(/^y/, '');
    const matches = word.match(/[aeiouy]{1,2}/g);
    return matches ? matches.length : 1;
}

/**
 * Calculate Flesch Reading Ease score.
 * 90-100: Very Easy | 80-90: Easy | 70-80: Fairly Easy
 * 60-70: Standard | 50-60: Fairly Difficult | 30-50: Difficult | 0-30: Very Difficult
 */
export function fleschReadingEase(text) {
    const words = text.match(/\b\w+\b/g) || [];
    const sentences = getSentences(text);
    
    if (words.length === 0 || sentences.length === 0) return 0;
    
    const totalSyllables = words.reduce((sum, w) => sum + countSyllables(w), 0);
    const avgSentenceLength = words.length / sentences.length;
    const avgSyllablesPerWord = totalSyllables / words.length;
    
    const score = 206.835 - (1.015 * avgSentenceLength) - (84.6 * avgSyllablesPerWord);
    return Math.max(0, Math.min(100, Math.round(score)));
}

/**
 * Get the label for a Flesch score.
 */
export function fleschLabel(score) {
    if (score >= 80) return 'Easy';
    if (score >= 60) return 'Standard';
    if (score >= 40) return 'Fairly Difficult';
    return 'Difficult';
}

/**
 * Analyze sentence lengths. Returns percentage of sentences that are too long (>20 words).
 */
export function longSentencePercentage(text) {
    const sentences = getSentences(text);
    if (sentences.length === 0) return 0;
    
    const longOnes = sentences.filter(s => {
        const wc = (s.match(/\b\w+\b/g) || []).length;
        return wc > 20;
    });
    
    return Math.round((longOnes.length / sentences.length) * 100);
}

/**
 * Analyze paragraph lengths. Returns number of paragraphs > 150 words.
 */
export function longParagraphs(html) {
    const paragraphs = getParagraphs(html);
    return paragraphs.filter(p => {
        const wc = (p.match(/\b\w+\b/g) || []).length;
        return wc > 150;
    }).length;
}

/**
 * Estimate passive voice percentage.
 * Looks for patterns like "was/were/is/are/been + past participle (ending in -ed/-en/-t)".
 */
export function passiveVoicePercentage(text) {
    const sentences = getSentences(text);
    if (sentences.length === 0) return 0;
    
    let passiveCount = 0;
    const pastParticiplePattern = /\b(ed|en|wn|nt|ht|lt|pt|ft|ct|xt|zed)\b/i;
    
    sentences.forEach(sentence => {
        const words = sentence.toLowerCase().split(/\s+/);
        for (let i = 0; i < words.length - 1; i++) {
            if (PASSIVE_AUXILIARIES.includes(words[i])) {
                // Check if the next word or the word after looks like a past participle
                const next = words[i + 1] || '';
                const afterNext = words[i + 2] || '';
                if (next.match(/ed$|en$|wn$|nt$/) || afterNext.match(/ed$|en$|wn$|nt$/)) {
                    passiveCount++;
                    break; // Count each sentence only once
                }
            }
        }
    });
    
    return Math.round((passiveCount / sentences.length) * 100);
}

/**
 * Check percentage of sentences starting with a transition word.
 */
export function transitionWordPercentage(text) {
    const sentences = getSentences(text);
    if (sentences.length === 0) return 0;
    
    const withTransition = sentences.filter(s => {
        const lower = s.toLowerCase();
        return TRANSITION_WORDS.some(tw => lower.startsWith(tw));
    });
    
    return Math.round((withTransition.length / sentences.length) * 100);
}

/**
 * Check subheading distribution. Returns true if content has subheadings
 * roughly every 300 words or fewer.
 */
export function subheadingDistribution(html) {
    const words = (stripHtml(html).match(/\b\w+\b/g) || []).length;
    if (words < 300) return { pass: true, ratio: 0 }; // Short content doesn't need subheadings
    
    const headings = (html.match(/<h[2-6][^>]*>/gi) || []).length;
    if (headings === 0) return { pass: false, ratio: words };
    
    const wordsPerHeading = Math.round(words / (headings + 1)); // +1 for intro before first heading
    return { pass: wordsPerHeading <= 350, ratio: wordsPerHeading };
}

/**
 * Run the full readability analysis and return structured results.
 */
export function analyzeReadability(html) {
    const plainText = stripHtml(html);
    const flesch = fleschReadingEase(plainText);
    const longSentPct = longSentencePercentage(plainText);
    const longParas = longParagraphs(html);
    const passivePct = passiveVoicePercentage(plainText);
    const transitionPct = transitionWordPercentage(plainText);
    const subheadings = subheadingDistribution(html);
    
    return {
        flesch: { score: flesch, label: fleschLabel(flesch), pass: flesch >= 60 },
        longSentences: { value: longSentPct, pass: longSentPct <= 25 },
        longParagraphs: { value: longParas, pass: longParas === 0 },
        passiveVoice: { value: passivePct, pass: passivePct <= 10 },
        transitionWords: { value: transitionPct, pass: transitionPct >= 30 },
        subheadings: { value: subheadings.ratio, pass: subheadings.pass },
    };
}
