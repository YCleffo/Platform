# PLATFORM — TYPING 3.0 ARCHITECTURE
## Monkeytype-Grade Touch Typing Trainer with Unicode Tokenization & Live Telemetry

**Status:** APPROVED & DEPLOYED  
**Version:** 3.0.0 (Education 08 Baseline)  
**Author:** Education Frontend & Media Architecture  
**Date:** November 24, 2026

---

## 1. Overview

Typing 3.0 elevates the Platform typing activity to modern competitive typing standards (inspired by Monkeytype), featuring:
1. **Unicode Tokenization via `Intl.Segmenter`**:
   Accurately segments words, grapheme clusters, and punctuation across Russian and English scripts, guaranteeing proper caret navigation and error detection.
2. **Real-Time High-Fidelity Telemetry**:
   - **Net WPM**: Normalized words per minute calculation `(correctChars / 5) / (elapsedMinutes)`.
   - **Raw WPM**: Gross speed `(totalTyped / 5) / (elapsedMinutes)`.
   - **Accuracy**: Percentage of correct characters over total keystrokes.
   - **Consistency**: Standard deviation of keystroke interval deltas (lower variability = higher consistency percentage).
3. **Per-Second Telemetry Sampling & SVG Sparkline**:
   Every 1000ms, a telemetry sample is recorded containing current WPM and Raw WPM, rendered as a responsive SVG sparkline trajectory upon exercise completion.
4. **Focus Mode & Keyboard Ergonomics**:
   - Instant quick restart via `Tab + Enter` or `Tab`.
   - Smooth CSS-animated caret positioning.
   - Strict contrast tokens compliant with WCAG AA.

---

## 2. Tokenization Implementation

```ts
function tokenizeWords(fullText: string, lang: 'ru' | 'en'): string[] {
  if (typeof Intl !== 'undefined' && 'Segmenter' in Intl) {
    const segmenter = new Intl.Segmenter(lang === 'ru' ? 'ru-RU' : 'en-US', {
      granularity: 'word',
    });
    const segments = Array.from(segmenter.segment(fullText));
    const words: string[] = [];
    let current = '';
    for (const seg of segments) {
      if (seg.isWordLike) {
        if (current) words.push(current);
        words.push(seg.segment);
        current = '';
      } else {
        current += seg.segment;
      }
    }
    if (current) words.push(current);
    return words.filter(Boolean);
  }
  return fullText.split(/(\s+)/).filter(Boolean);
}
```

---

## 3. Telemetry Verification & Anti-Cheat

Typing 3.0 sends client telemetry metrics upon completion:
- `typedCharacters`: Total characters typed.
- `corrections`: Count of backspace and correction events.
- `inputErrors`: Observed character mismatch count.
- `wpmSamples`: Array of per-second speed telemetry.

Backend `TypingActivityHandler` validates:
1. Duration constraint (`elapsedSeconds` within configured time bounds).
2. Theoretical speed threshold (flags unrealistic WPM spikes > 280 WPM without appropriate keystroke cadence).
3. Accuracy bounds and minimum completion length.
