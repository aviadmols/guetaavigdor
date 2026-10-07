# מנגנון חיפוש עמיד לשגיאות כתיב בעברית

מדריך מלא לבניית חיפוש מוצרים שמוצא תוצאות גם כשמקלידים בשגיאות כתיב, בכתיב מלא או חסר, ביחיד או ברבים, וגם כשהמקלדת נשארה באנגלית. בלי מודל AI, בלי שירות חיצוני ובלי עלות שוטפת.

המדריך עומד בפני עצמו: הוא כולל את העיקרון, את האלגוריתם עם כל המספרים, קוד מלא ב-JavaScript וב-PHP, ושלבי שילוב באתר חדש. המנגנון נבנה ונבדק בחנות WooCommerce של גואטה אביגדור (1,131 מוצרים), אבל הוא מתאים לכל קטלוג בעברית.

> **להעברה לפרויקט אחר:** פתחו את הפרויקט החדש, צרפו את הקובץ הזה, והדביקו את הפרומפט מסעיף 12.

---

## 1. הבעיה

חיפוש רגיל משווה מחרוזות: מוצר נמצא רק אם כל מילה שהוקלדה מופיעה בו כפי שהיא. בעברית יש הרבה דרכים לכתוב את אותה מילה, וללקוחות זה נראה כאילו המוצר לא קיים:

| מה הקלידו | למה התכוונו | חיפוש רגיל |
|---|---|---|
| מקיטא | מקיטה | 0 תוצאות |
| איסקורית | בורג איסכורית | 0 תוצאות |
| אורן מוקצא | עץ אורן מוקצע | 0 תוצאות |
| ncrdv (מקלדת באנגלית) | מברגה | 0 תוצאות |

## 2. הדרישות

1. **עברית כמו שמקלידים אותה**: כתיב מלא וחסר, יחיד ורבים, אותיות סופיות, אותיות שנשמעות דומה, ומקלדת שנשארה באנגלית.
2. **בלי עלות שוטפת**: בלי מודל AI. אם יהיה צורך בעתיד, רק המודל הזול ביותר.
3. **מהיר**: תוצאות בזמן הקלדה, בלי לפנות לשרת בכל הקשה.
4. **סריקת הקטלוג פעם ביום, או בכפתור** בממשק הניהול.
5. **אותן תוצאות בכל מקום**: חלון ההצעות ודף "כל התוצאות" מציגים את אותם מוצרים באותו סדר.

## 3. למה בלי מודל AI

"חיפוש וקטורי" לא מחייב מודל שפה. כשהבעיה היא **איות** ולא **משמעות**, וקטורים של חלקי מילים (character n-grams) עושים את העבודה טוב יותר, בחינם ומקומית.

| קריטריון | embeddings של מודל AI | וקטורים של חלקי מילים |
|---|---|---|
| עלות | תשלום לפי שימוש ומפתח API | אפס |
| זמן תגובה | קריאה לשרת חיצוני בכל חיפוש | 1 עד 9 מילישניות, בלי רשת |
| שגיאות כתיב | תלוי במודל | החוזקה העיקרית |
| מילים נרדפות (קרש ↔ עץ) | החוזקה העיקרית | לא מכסה |
| פרטיות | השאילתות יוצאות לספק | הכל נשאר באתר |

## 4. ארכיטקטורה

```
קטלוג המוצרים (שם, מק"ט, קטגוריות, תגיות)
        │
        ▼  פעם ביום, או בכפתור בממשק הניהול
בניית אינדקס  ──►  קובץ JSON סטטי (index-<hash>.json)
        │                         │
        ▼                         ▼
דפדפן (JavaScript)          שרת (PHP)
הצעות בזמן הקלדה            דף "כל התוצאות", סינון, "טען עוד"
```

עקרונות:

- **אינדקס אחד, שני מנועים זהים.** הדפדפן והשרת קוראים את אותו קובץ ומריצים את אותו אלגוריתם (הקוד בסעיפים 8 ו-9). כך דף התוצאות מציג בדיוק את מה שההצעות הבטיחו.
- **הווקטורים לא נשמרים בקובץ.** הם מחושבים מהמילים כשהקובץ נטען, בשבריר שנייה. הקובץ נשאר קטן: כ-420KB ל-1,131 מוצרים, כולל מחירים ותמונות.
- **שם הקובץ כולל hash של התוכן.** בנייה שלא שינתה כלום משאירה את אותה כתובת, והדפדפן לא מוריד אותו שוב. אפשר לשמור אותו במטמון לשנה.
- **הדפדפן מוריד את הקובץ פעם אחת**, בסימן הראשון שמישהו עומד לחפש (פוקוס על השדה או לחיצה עליו). מכאן כל הקשה היא חישוב בזיכרון.

## 5. האינדקס

כל מוצר הוא רשומה עם הכותרת, ומחרוזת אחת של מילים נוספות לחיפוש:

```json
{ "id": 11678, "title": "עץ אורן מוקצע בפרופיל 42X92 מ\"מ (5X10)", "keywords": "10026 עצים עץ אורן" }
```

- `keywords`: שמות הקטגוריות והתגיות של המוצר, והמק"ט.
- רשומה יכולה לשאת גם כתובת, תמונה ומחיר לתצוגה; המנוע משתמש רק ב-`id`, `title` ו-`keywords`.
- כדאי לכלול במבנה מספר גרסה (format). כשמבנה הרשומה משתנה, מעלים אותו, והאתר בונה קובץ חדש מיד בלי לחכות ליום הבא.

**מוצרים שנכללים:** מפורסמים, ולא מוסתרים מהחיפוש.

**תזמון:** אירוע יומי, וכפתור "בניית האינדקס עכשיו" בממשק הניהול. אם רוצים שמוצר חדש יופיע מיד, אפשר להוסיף בנייה כמה דקות אחרי שמירת מוצר (עם דחייה, כדי ששמירה של עשרה מוצרים תעלה בנייה אחת).

## 6. האלגוריתם

### 6.1 נרמול

לפני כל השוואה, גם של הקטלוג וגם של מה שהוקלד:

1. אותיות קטנות.
2. הסרת ניקוד וטעמים (`U+0591` עד `U+05C7`).
3. הסרת גרשיים ומירכאות: `׳ ״ ' " \``.
4. אותיות סופיות לצורתן הרגילה: ך→כ, ם→מ, ן→נ, ף→פ, ץ→צ. כך "עץ" ו"עצים" חולקות את אותן אותיות.
5. כל מה שאינו אות או ספרה הופך לרווח.

### 6.2 שורש כתיב

למילה עברית בת 3 אותיות ומעלה:

1. מילה ארוכה מ-4 אותיות מאבדת סיומת רבים `ים` או `ות` (אחרי הנרמול `ים` נכתבת `ימ`).
2. אחרי האות הראשונה מוסרות `ו` ו-`י`, כדי שכתיב מלא וחסר ייפגשו.

```
ברגים  → ברג
בורג   → ברג      ← אותו שורש
מוקצע  → מקצע
מקצועי → מקצע
```

### 6.3 צליל

השורש, ואחריו איחוד אותיות שנשמעות דומה:

| אות | הופכת ל |
|---|---|
| ט | ת |
| ק, ח | כ |
| ע, ה | א |
| ס | ש |

```
מקיטה → מקטה → מכתא
מקיטא → מקטא → מכתא      ← אותו צליל
```

### 6.4 הווקטור של מילה

הווקטור הוא אוסף החלקים בני שלוש האותיות של המילה, עם רווח בתחילתה ובסופה, **ועוד** החלקים של הצליל שלה, מסומנים ב-`~` כדי שלא יתערבבו עם חלקי המילה עצמה. הווקטור מנורמל לאורך 1.

```
"מקיטה"        →  " מק", "מקי", "קיט", "יטה", "טה "
צליל "~מכתא"   →  " ~מ", "~מכ", "מכת", "כתא", "תא "
```

### 6.5 דמיון בין מילים

הדמיון הוא המכפלה הסקלרית של שני הווקטורים המנורמלים (cosine similarity). דוגמה שנמדדה:

```
"מקיטא" מול "מקיטה":
  משותפים: " מק", "מקי", "קיט" + 5 חלקי הצליל = 8 מתוך 10 בכל מילה
  קוסינוס = 0.8
```

כדי לא להשוות מול כל מילה בנפרד, נבנה **אינדקס הפוך**: מכל חלק לרשימת המילים שמכילות אותו, עם המשקל. מילה שהוקלדה נסכמת רק מול המילים שחולקות איתה חלק.

### 6.6 התאמת מילה שהוקלדה למילים בקטלוג

| מקרה | ציון | דוגמה |
|---|---|---|
| אותה מילה | 1.0 | בורג ↔ בורג |
| עדיין מקלידים: המילה בקטלוג מתחילה במה שהוקלד (2 אותיות ומעלה) | `0.9 − 0.02 × הפרש האורך` | מבר ↔ מברגה |
| אותו שורש כתיב (3 אותיות ומעלה) | 0.85 | ברגים ↔ בורג |
| אות שימוש בתחילת המילה בקטלוג (ו ה ב ל מ ש כ) | 0.85 | לעץ ↔ עץ |
| כל השאר | הקוסינוס | איסקורית ↔ איסכורית (0.79) |

כללים נוספים:

- **אות שימוש במה שהוקלד:** מילה שמתחילה ב-ו/ה/ב/ל/מ/ש/כ ואורכה מעל 3 נבדקת גם בלעדיה ("לעץ" נבדקת גם כ"עץ"), בציון × 0.95.
- **סף מינימלי:** 0.45.
- **סף יחסי:** נשמרות רק מילים שציונן לפחות **80% מהמילה הטובה ביותר**. בלי הכלל הזה "מקיטא" משך גם את "מחוטא", כי הצליל שלהן כמעט זהה. כשנמצאה מילה כפי שהוקלדה (ציון 1), נשארות רק מילים קרובות מאוד אליה.

### 6.7 דירוג המוצרים

1. **התאמה מדויקת קודם.** מוצר שמכיל את כל המילים כמו שהן מקבל דרגה: 0 = שם זהה לשאילתה, 1 = השם מתחיל בשאילתה, 2 = השאילתה מתחילה מילה בשם, 3 = מופיעה בתוך השם, 4 = כל המילים בשם, 5 = חלק מהמילים נמצאו רק במילים הנוספות. הוא מקבל `10 − דרגה`.
2. **ציון קרבה ממוצע.** לכל מילה בשאילתה נלקחת המילה הקרובה ביותר במוצר. מילה בשם המוצר שווה × 1, מילה מהמילים הנוספות × 0.7. הציונים מסוכמים ומחולקים במספר המילים.
3. **סלחנות לשאילתות ארוכות.** מוצר נכנס אם יש לו התאמה לכל מילה; משלוש מילים ומעלה מותר לפספס אחת.
4. **שוויון:** הכותרת הקצרה יותר קודמת.

הציון הסופי הוא `(10 − דרגה, אם יש התאמה מדויקת) + ממוצע הקרבה`. כך מי שכותב נכון לא נפגע: התוצאות המדויקות תמיד למעלה, וההתאמות הקרובות אחריהן.

### 6.8 מקלדת שנשארה באנגלית

שאילתה שכולה אותיות לטיניות (ו-`; , .`) מתורגמת גם למקשים העבריים שבאותו מקום (`n c r d v` → `מ ב ר ג ה`). שתי הקריאות מחושבות, ונבחרת זו שהתוצאה הראשונה שלה קיבלה ציון גבוה יותר. כך "milwakee" נשאר באנגלית ומוצא Milwaukee, ו-"ncrdv" עובר לעברית ומוצא מברגה.

### 6.9 קטגוריות, תגיות ומאמרים בחלון ההצעות

לצד המוצרים מוצגים קטגוריות, תגיות ומאמרים שהשם שלהם מכיל את השאילתה, **או** מילה קרובה ל**לפחות מחצית** ממילות השאילתה. את המילים הקרובות לוקחים מהחיפוש שכבר רץ על המוצרים (`close` בתוצאה). כך "צבא לעץ" מעלה את הקטגוריה "צבעים ולכות".

## 7. תוצאות שנמדדו

על קטלוג של 1,131 מוצרים, בדפדפן ובשרת, עם תוצאות זהות באותו סדר:

| מה הקלידו | מה נמצא ראשון | מוצרים |
|---|---|---|
| אורן מוקצא | עץ אורן מוקצע | 57 |
| איסקורית | בורג איסכורית | 1 |
| מקיטא | מוצרי מקיטה | 11 |
| ncrdv | מברגה | 23 |
| צבא לעץ | צבע לעץ | 16 |
| milwakee | Milwaukee | 9 |
| פרגולא | פרגולה | 5 |
| פנל מבודת | פנל מבודד | 10 |

זמנים: 1 עד 9 מילישניות לחיפוש בדפדפן. בשרת, בניית המבנה מהקובץ כ-0.2 שניות פעם אחת לבקשה, וכל חיפוש 3 עד 9 מילישניות.

## 8. הקוד: JavaScript (דפדפן או Node)

מנוע שלם, בלי תלויות. `buildIndex(items)` פעם אחת אחרי שהקובץ נטען, ואז `search(index, query)` בכל הקשה.

```js
/**
 * Typo-tolerant Hebrew search: word vectors of letter pieces, no AI.
 *
 * const index = buildIndex(items);   // items: [{ id, title, keywords }]
 * const hits  = search(index, 'מקיטא');  // [{ item, score }], best first
 */
const HEBREW_WORD = /^[א-ת]+$/;
const PREFIX_LETTER = /^[והבלמשכ]/;
const FINALS = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };
const ALIKE = { 'ט': 'ת', 'ק': 'כ', 'ח': 'כ', 'ע': 'א', 'ס': 'ש', 'ה': 'א' };
const KEYBOARD = {
	e: 'ק', r: 'ר', t: 'א', y: 'ט', u: 'ו', i: 'נ', o: 'מ', p: 'פ',
	a: 'ש', s: 'ד', d: 'ג', f: 'כ', g: 'ע', h: 'י', j: 'ח', k: 'ל', l: 'כ', ';': 'פ',
	z: 'ז', x: 'ס', c: 'ב', v: 'ה', b: 'נ', n: 'מ', m: 'צ', ',': 'ת', '.': 'צ'
};

function normalize(text) {
	return String(text == null ? '' : text)
		.toLowerCase()
		.replace(/[֑-ׇ]/g, '')
		.replace(/[׳״'"`]/g, '')
		.replace(/[ךםןףץ]/g, (letter) => FINALS[letter])
		.replace(/[^\p{L}\p{N}]+/gu, ' ')
		.trim();
}

// ברגים and בורג are both ברג.
function stem(word) {
	if (!HEBREW_WORD.test(word) || word.length < 3) return word;
	const base = word.length > 4 ? word.replace(/(ימ|ות)$/, '') : word;
	return base.charAt(0) + base.slice(1).replace(/[וי]/g, '');
}

function sound(word) {
	return stem(word).replace(/[טקחעסה]/g, (letter) => ALIKE[letter]);
}

function pieces(word, out) {
	const padded = ' ' + word + ' ';
	for (let i = 0; i < padded.length - 2; i++) {
		const piece = padded.substr(i, 3);
		out[piece] = (out[piece] || 0) + 1;
	}
	return out;
}

function wordVector(word) {
	const vector = pieces(word, {});
	const heard = sound(word);
	if (heard !== word) pieces('~' + heard, vector);
	let length = 0;
	for (const piece in vector) length += vector[piece] * vector[piece];
	length = Math.sqrt(length) || 1;
	for (const piece in vector) vector[piece] /= length;
	return vector;
}

function fromKeyboard(query) {
	if (!/^[a-z;,. ]+$/.test(query) || !/[a-z]/.test(query)) return '';
	return query.replace(/[a-z;,.]/g, (key) => KEYBOARD[key] || key);
}

/**
 * items: [{ id, title, keywords }] — keywords: categories, tags, SKU, as one string.
 */
function buildIndex(items) {
	const products = items.map((item) => {
		const name = normalize(item.title);
		return { item, name, named: ' ' + name + ' ', hay: normalize(item.title + ' ' + (item.keywords || '')) };
	});
	const list = [];
	const holders = new Map();
	const postings = new Map();

	products.forEach((product, at) => {
		for (const word of product.hay.split(' ')) {
			if (!word) continue;
			if (!holders.has(word)) { holders.set(word, []); list.push(word); }
			const owners = holders.get(word);
			if (owners[owners.length - 1] !== at) owners.push(at);
		}
	});

	list.forEach((word, w) => {
		const vector = wordVector(word);
		for (const piece in vector) {
			if (!postings.has(piece)) postings.set(piece, []);
			postings.get(piece).push(w, vector[piece]);
		}
	});

	return { products, list, holders, postings };
}

// Catalogue words close to one typed word, with how close (1 = the word itself).
function closeWords(index, typed) {
	const found = new Map();
	const variants = [typed];
	if (PREFIX_LETTER.test(typed) && typed.length > 3) variants.push(typed.slice(1)); // לעץ → עץ

	variants.forEach((variant, position) => {
		const vector = wordVector(variant);
		const scores = new Float64Array(index.list.length);
		const base = stem(variant);

		for (const piece in vector) {
			const list = index.postings.get(piece);
			if (!list) continue;
			for (let i = 0; i < list.length; i += 2) scores[list[i]] += vector[piece] * list[i + 1];
		}

		index.list.forEach((candidate, i) => {
			let score = scores[i];
			if (candidate === variant) score = 1;
			else if (variant.length >= 2 && candidate.startsWith(variant)) score = Math.max(score, 0.9 - 0.02 * (candidate.length - variant.length));
			else if (variant.length >= 3 && stem(candidate) === base) score = Math.max(score, 0.85);
			else if (PREFIX_LETTER.test(candidate) && candidate.length > 3 && candidate.slice(1) === variant) score = Math.max(score, 0.85);
			if (position) score *= 0.95;
			if (score >= 0.45 && (found.get(candidate) || 0) < score) found.set(candidate, score);
		});
	});

	let best = 0;
	for (const score of found.values()) best = Math.max(best, score);
	const floor = Math.max(0.45, best * 0.8);
	for (const [word, score] of found) if (score < floor) found.delete(word);
	return found;
}

// Holds every word as typed: 0 (best) to 5; -1 when it does not.
function exactTier(product, query, tokens) {
	for (const token of tokens) if (!product.hay.includes(token)) return -1;
	if (product.name === query) return 0;
	if (product.name.startsWith(query)) return 1;
	if ((' ' + product.name).includes(' ' + query)) return 2;
	if (product.name.includes(query)) return 3;
	for (const token of tokens) if (!(' ' + product.name).includes(' ' + token)) return 5;
	return 4;
}

function rankProducts(index, query) {
	const tokens = query.split(' ');
	const close = tokens.map((token) => closeWords(index, token));
	const count = index.products.length;
	const score = new Float64Array(count);
	const hits = new Uint8Array(count);
	const needed = tokens.length <= 2 ? tokens.length : tokens.length - 1;

	for (const words of close) {
		const best = new Map();
		for (const [word, closeness] of words) {
			for (const at of index.holders.get(word) || []) {
				// A word in the name counts for more than one in the keywords.
				const value = closeness * (index.products[at].named.includes(' ' + word + ' ') ? 1 : 0.7);
				if ((best.get(at) || 0) < value) best.set(at, value);
			}
		}
		for (const [at, value] of best) { score[at] += value; hits[at]++; }
	}

	const found = [];
	index.products.forEach((product, at) => {
		const tier = exactTier(product, query, tokens);
		if (tier < 0 && hits[at] < needed) return;
		found.push({ item: product.item, score: (tier >= 0 ? 10 - tier : 0) + score[at] / tokens.length, close });
	});

	return found.sort((a, b) => b.score - a.score || a.item.title.length - b.item.title.length);
}

function search(index, raw) {
	const query = normalize(raw);
	if (!query) return [];
	let found = rankProducts(index, query);
	const typed = fromKeyboard(query);
	if (typed) {
		const hebrew = rankProducts(index, typed);
		if (hebrew.length && (!found.length || hebrew[0].score > found[0].score)) found = hebrew;
	}
	return found;
}

if (typeof module !== 'undefined') module.exports = { normalize, stem, sound, wordVector, buildIndex, closeWords, search };
```

שימוש:

```js
const index = buildIndex(items);              // items: [{ id, title, keywords }]
const hits = search(index, 'מקיטא');           // [{ item, score, close }]
hits.slice(0, 6).forEach((hit) => console.log(hit.item.title));
```

## 9. הקוד: PHP (שרת)

אותו אלגוריתם, שורה מול שורה. דורש PHP 7.4 ומעלה עם `mbstring`.

```php
<?php
/**
 * Typo-tolerant Hebrew search, server side: the same algorithm as search-core.js.
 *
 * $index = search_build_index( $items );          // [ [ 'id' => …, 'title' => …, 'keywords' => … ], … ]
 * $ids   = search_ids( $index, 'מקיטא' );          // ids, best first
 */

function search_normalize( $text ) {
	$text = mb_strtolower( (string) $text, 'UTF-8' );
	$text = preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $text );
	$text = preg_replace( '/[\x{05F3}\x{05F4}\'"`]/u', '', $text );
	$text = strtr( $text, [ 'ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ' ] );

	return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
}

function search_stem( $word ) {
	if ( ! preg_match( '/^[א-ת]+$/u', $word ) || mb_strlen( $word ) < 3 ) {
		return $word;
	}

	$base = mb_strlen( $word ) > 4 ? preg_replace( '/(ימ|ות)$/u', '', $word ) : $word;

	return mb_substr( $base, 0, 1 ) . preg_replace( '/[וי]/u', '', mb_substr( $base, 1 ) );
}

function search_sound( $word ) {
	return strtr( search_stem( $word ), [ 'ט' => 'ת', 'ק' => 'כ', 'ח' => 'כ', 'ע' => 'א', 'ס' => 'ש', 'ה' => 'א' ] );
}

function search_word_vector( $word ) {
	$vector = [];
	$heard  = search_sound( $word );
	$sides  = $heard !== $word ? [ $word, '~' . $heard ] : [ $word ];

	foreach ( $sides as $side ) {
		$letters = preg_split( '//u', ' ' . $side . ' ', -1, PREG_SPLIT_NO_EMPTY );

		for ( $i = 0, $n = count( $letters ) - 2; $i < $n; $i++ ) {
			$piece            = $letters[ $i ] . $letters[ $i + 1 ] . $letters[ $i + 2 ];
			$vector[ $piece ] = ( $vector[ $piece ] ?? 0 ) + 1;
		}
	}

	$length = sqrt( array_sum( array_map( static fn( $w ) => $w * $w, $vector ) ) ) ?: 1.0;

	return array_map( static fn( $w ) => $w / $length, $vector );
}

function search_from_keyboard( $query ) {
	if ( ! preg_match( '/^[a-z;,. ]+$/', $query ) || ! preg_match( '/[a-z]/', $query ) ) {
		return '';
	}

	return strtr( $query, [
		'e' => 'ק', 'r' => 'ר', 't' => 'א', 'y' => 'ט', 'u' => 'ו', 'i' => 'נ', 'o' => 'מ', 'p' => 'פ',
		'a' => 'ש', 's' => 'ד', 'd' => 'ג', 'f' => 'כ', 'g' => 'ע', 'h' => 'י', 'j' => 'ח', 'k' => 'ל', 'l' => 'כ', ';' => 'פ',
		'z' => 'ז', 'x' => 'ס', 'c' => 'ב', 'v' => 'ה', 'b' => 'נ', 'n' => 'מ', 'm' => 'צ', ',' => 'ת', '.' => 'צ',
	] );
}

function search_build_index( array $items ) {
	$products = [];
	$holders  = [];
	$list     = [];
	$postings = [];

	foreach ( array_values( $items ) as $at => $item ) {
		$name       = search_normalize( $item['title'] );
		$hay        = search_normalize( $item['title'] . ' ' . ( $item['keywords'] ?? '' ) );
		$products[] = [ 'id' => $item['id'], 'title' => (string) $item['title'], 'name' => $name, 'named' => ' ' . $name . ' ', 'hay' => $hay ];

		foreach ( explode( ' ', $hay ) as $word ) {
			if ( '' === $word ) {
				continue;
			}

			if ( ! isset( $holders[ $word ] ) ) {
				$holders[ $word ] = [];
				$list[]           = (string) $word;
			}

			if ( end( $holders[ $word ] ) !== $at ) {
				$holders[ $word ][] = $at;
			}
		}
	}

	foreach ( $list as $w => $word ) {
		foreach ( search_word_vector( $word ) as $piece => $weight ) {
			$postings[ $piece ][] = [ $w, $weight ];
		}
	}

	return compact( 'products', 'list', 'holders', 'postings' );
}

function search_close_words( array $index, $typed ) {
	$found    = [];
	$variants = [ $typed ];

	if ( preg_match( '/^[והבלמשכ]/u', $typed ) && mb_strlen( $typed ) > 3 ) {
		$variants[] = mb_substr( $typed, 1 );
	}

	foreach ( $variants as $position => $variant ) {
		$scores = [];
		$base   = search_stem( $variant );
		$length = mb_strlen( $variant );

		foreach ( search_word_vector( $variant ) as $piece => $weight ) {
			foreach ( $index['postings'][ $piece ] ?? [] as [ $w, $other ] ) {
				$scores[ $w ] = ( $scores[ $w ] ?? 0 ) + $weight * $other;
			}
		}

		foreach ( $index['list'] as $w => $candidate ) {
			$score = $scores[ $w ] ?? 0;

			if ( $candidate === $variant ) {
				$score = 1;
			} elseif ( $length >= 2 && 0 === strpos( $candidate, $variant ) ) {
				$score = max( $score, 0.9 - 0.02 * ( mb_strlen( $candidate ) - $length ) );
			} elseif ( $length >= 3 && search_stem( $candidate ) === $base ) {
				$score = max( $score, 0.85 );
			} elseif ( mb_strlen( $candidate ) > 3 && preg_match( '/^[והבלמשכ]/u', $candidate ) && mb_substr( $candidate, 1 ) === $variant ) {
				$score = max( $score, 0.85 );
			}

			if ( $position ) {
				$score *= 0.95;
			}

			if ( $score >= 0.45 && ( $found[ $candidate ] ?? 0 ) < $score ) {
				$found[ $candidate ] = $score;
			}
		}
	}

	$floor = $found ? max( 0.45, max( $found ) * 0.8 ) : 0.45;

	return array_filter( $found, static fn( $score ) => $score >= $floor );
}

function search_exact_tier( array $product, $query, array $tokens ) {
	foreach ( $tokens as $token ) {
		if ( false === strpos( $product['hay'], $token ) ) {
			return -1;
		}
	}

	if ( $product['name'] === $query ) {
		return 0;
	}

	if ( 0 === strpos( $product['name'], $query ) ) {
		return 1;
	}

	if ( false !== strpos( ' ' . $product['name'], ' ' . $query ) ) {
		return 2;
	}

	if ( false !== strpos( $product['name'], $query ) ) {
		return 3;
	}

	foreach ( $tokens as $token ) {
		if ( false === strpos( ' ' . $product['name'], ' ' . $token ) ) {
			return 5;
		}
	}

	return 4;
}

function search_rank( array $index, $query ) {
	$tokens = explode( ' ', $query );
	$score  = [];
	$hits   = [];
	$found  = [];
	$needed = count( $tokens ) <= 2 ? count( $tokens ) : count( $tokens ) - 1;

	foreach ( $tokens as $token ) {
		$best = [];

		foreach ( search_close_words( $index, $token ) as $word => $closeness ) {
			foreach ( $index['holders'][ $word ] ?? [] as $at ) {
				$value = $closeness * ( false !== strpos( $index['products'][ $at ]['named'], ' ' . $word . ' ' ) ? 1 : 0.7 );

				if ( ( $best[ $at ] ?? 0 ) < $value ) {
					$best[ $at ] = $value;
				}
			}
		}

		foreach ( $best as $at => $value ) {
			$score[ $at ] = ( $score[ $at ] ?? 0 ) + $value;
			$hits[ $at ]  = ( $hits[ $at ] ?? 0 ) + 1;
		}
	}

	foreach ( $index['products'] as $at => $product ) {
		$tier = search_exact_tier( $product, $query, $tokens );

		if ( $tier < 0 && ( $hits[ $at ] ?? 0 ) < $needed ) {
			continue;
		}

		$found[] = [ 'id' => $product['id'], 'title' => $product['title'], 'score' => ( $tier >= 0 ? 10 - $tier : 0 ) + ( $score[ $at ] ?? 0 ) / count( $tokens ) ];
	}

	usort( $found, static fn( $a, $b ) => $b['score'] <=> $a['score'] ?: mb_strlen( $a['title'] ) <=> mb_strlen( $b['title'] ) );

	return $found;
}

function search_ids( array $index, $raw ) {
	$query = search_normalize( $raw );

	if ( '' === $query ) {
		return [];
	}

	$found = search_rank( $index, $query );
	$typed = search_from_keyboard( $query );

	if ( $typed ) {
		$hebrew = search_rank( $index, $typed );

		if ( $hebrew && ( ! $found || $hebrew[0]['score'] > $found[0]['score'] ) ) {
			$found = $hebrew;
		}
	}

	return array_column( $found, 'id' );
}
```

שימוש:

```php
$items = json_decode( file_get_contents( $index_file ), true );   // אותו קובץ שהדפדפן קורא
$index = search_build_index( $items );
$ids   = search_ids( $index, $_GET['s'] ?? '' );                   // מזהים, הטוב ביותר ראשון
```

**חשוב:** שני המימושים חייבים להישאר זהים. כל שינוי בסף, במשקל או בכלל נעשה בשניהם, ואחריו בודקים שהם מחזירים אותם מזהים באותו סדר (סעיף 11).

## 10. שילוב באתר

### 10.1 בכל אתר

1. **בניית האינדקס:** פונקציה בשרת שקוראת את המוצרים, בונה מערך של `{ id, title, keywords }` (ומה שצריך לתצוגה: כתובת, תמונה, מחיר), ושומרת אותו כקובץ JSON סטטי ששמו כולל hash של התוכן. מוחקים קבצים ישנים.
2. **תזמון:** אירוע יומי, וכפתור בממשק הניהול שבונה מיד ומציג מתי נבנה, כמה מוצרים וגודל הקובץ.
3. **חלון ההצעות:** הכתובת של הקובץ נמסרת לדף. בפוקוס הראשון על שדה החיפוש מורידים אותו ומריצים `buildIndex`. כל הקשה, אחרי השהיה קצרה (60 מילישניות), מריצה `search` ומציגה 6 מוצרים, קטגוריות ותגיות (סעיף 6.9), וקישור "הצג את כל N התוצאות".
4. **דף כל התוצאות:** השרת מריץ `search_ids` על אותו קובץ ומציג את המוצרים לפי הסדר שהתקבל. סינון, מיון ו"טען עוד" ממשיכים לעבוד בתוך הרשימה הזו.
5. **גיבוי:** אם הקובץ לא נטען, חוזרים לחיפוש הרגיל של האתר.

### 10.2 ב-WordPress ו-WooCommerce

- **הקובץ:** ב-`wp-content/uploads/<תיקייה>/index-<hash>.json`, עם `.htaccess` שנותן לו `Cache-Control: public, max-age=31536000, immutable`.
- **המוצרים:** `WP_Query` על `product`, בקבוצות של 200, עם `_prime_post_caches` לכל קבוצה, בלי מוצרים שב-`product_visibility` מסומנים `exclude-from-search`.
- **התזמון:** `wp_schedule_event( time(), 'daily', '<hook>' )` ב-`init` אם עוד לא מתוזמן, ו-`wp_clear_scheduled_hook` ב-`switch_theme`.
- **דף התוצאות:** ב-`pre_get_posts`, לשאילתה הראשית של חיפוש מוצרים, מגדירים `post__in` לתוצאות (או `[0]` כשאין) ו-`orderby => post__in`, ומסמנים את השאילתה. ב-`posts_search` מחזירים מחרוזת ריקה לשאילתה המסומנת, כדי ש-WordPress לא יוסיף תנאי LIKE שיפיל את ההתאמות הקרובות.
- **גריד מותאם (shortcode או AJAX):** אותו דבר: `post__in` ו-`orderby => post__in` כשהמשתמש לא בחר מיון אחר, ושליחת מילת החיפוש בכל בקשת "טען עוד".
- **שימו לב:** בדף החיפוש של WordPress מילת החיפוש נמצאת ב-`s`. גריד שקורא פרמטר אחר יתעלם ממנה ויציג את כל הקטלוג. זה באג אמיתי שנמצא בבנייה המקורית.

### 10.3 סטטיסטיקה (מומלץ)

טבלה שרושמת חיפושים ולחיצות, ודף בממשק הניהול:

- **חיפוש נספר** אחרי הפסקה של 2 שניות בהקלדה, בלחיצה על תוצאה, או בפתיחת דף התוצאות.
- **מזהה לשונית** אקראי ב-`sessionStorage` מאחד את אותה שאילתה בהצעות ובדף התוצאות לחיפוש אחד, ומחבר לחיצה לחיפוש שממנו באה. שליחה ב-`navigator.sendBeacon`, שעובד גם כשהדף עוזב.
- **לא שומרים** IP, משתמש או עוגייה. לא סופרים מנהלים ורובוטים. מגבילים קצב (למשל 120 אירועים ב-10 דקות לכתובת). מוחקים אחרי שנה.
- **הדוח:** מספר חיפושים, אחוז שהובילו ללחיצה, אחוז בלי תוצאות, החיפושים הנפוצים, **החיפושים בלי תוצאות** (הבסיס לרשימת מילים נרדפות), והמוצרים שנלחצו הכי הרבה מתוך החיפוש.

## 11. בדיקה

1. **שני המימושים זהים.** מריצים את אותן שאילתות ב-JavaScript וב-PHP על אותו קובץ ומשווים את רשימות המזהים:

```js
// node compare.js  →  כותב js-out.json
const { buildIndex, search } = require('./search-core.js');
const items = require('./items.json');
const index = buildIndex(items);
const queries = ['מקיטא', 'איסקורית', 'ncrdv', 'צבא לעץ', 'ברגים'];
const out = {};
for (const q of queries) out[q] = search(index, q).map((hit) => hit.item.id);
require('fs').writeFileSync('js-out.json', JSON.stringify(out));
```

```php
// php compare.php  →  SAME ORDER לכל שאילתה
require __DIR__ . '/search-core.php';
$index = search_build_index( json_decode( file_get_contents( 'items.json' ), true ) );
foreach ( json_decode( file_get_contents( 'js-out.json' ), true ) as $q => $ids ) {
	echo $q, ': ', search_ids( $index, $q ) === $ids ? 'SAME ORDER' : 'DIFFERENT', "\n";
}
```

2. **מול הקטלוג האמיתי:** שגיאות כתיב נפוצות בתחום, מילים ברבים וביחיד, ושאילתה במקלדת באנגלית. כל אחת צריכה להביא את המוצר הנכון ראשון.
3. **באתר:** חיפוש בשדה, ואז "הצג את כל התוצאות". המספר והסדר זהים, ו"טען עוד" נשאר בתוך החיפוש.

## 12. פרומפט לפרויקט חדש

צרפו את הקובץ הזה והדביקו:

```text
צרפתי את search-mechanism.md: מנגנון חיפוש עמיד לשגיאות כתיב בעברית, בלי מודל AI.
אני רוצה אותו בפרויקט הזה.

1. קרא את הקובץ כולו, ואז את הקוד של החיפוש הקיים בפרויקט: איפה שדה החיפוש,
   איך נטענות ההצעות, ואיך נבנה דף התוצאות.
2. השתמש בקוד מסעיפים 8 ו-9 כמו שהוא, בלי לשנות ספים, משקלים או כללים.
3. בנה את האינדקס לפי סעיף 5, עם בנייה יומית וכפתור בממשק הניהול.
4. חבר את חלון ההצעות ואת דף כל התוצאות לפי סעיף 10, כך ששניהם מציגים אותם
   מוצרים באותו סדר, וגם סינון ו"טען עוד" נשארים בתוך החיפוש.
5. בדוק לפי סעיף 11: אותן תוצאות ב-JavaScript וב-PHP, ותוצאות נכונות לשגיאות
   כתיב מהתחום של האתר הזה.
6. אם בעיצוב או במבנה של הפרויקט משהו לא מתאים לסעיף 10, תגיד לי לפני שאתה משנה.
```

## 13. מגבלות והמשך

- **מילים נרדפות.** "קרש" לא ימצא מוצר שנקרא "עץ" אם המילה "קרש" לא מופיעה בו. הצעד הבא, בלי עלות: רשימת מילים נרדפות שנבנית מדוח "חיפושים בלי תוצאות", ומתווספת ל-`keywords` של המוצרים בזמן בניית האינדקס.
- **חיפוש סמנטי.** אם הרשימה לא תספיק: embeddings של המודל הזול של OpenAI (`text-embedding-3-small`). סריקת הקטלוג פעם ביום עולה שבריר סנט, אבל כל חיפוש ידרוש קריאה מהשרת, יהיה איטי יותר ויחייב מפתח API.
- **תוצאות חלשות בסוף הרשימה.** ב"מברגה" מופיעים בסוף גם "בורג הברגה", כי "הברגה" קרובה באיות. בחלון של 6 הצעות זה לא נראה. בדף התוצאות אפשר להעלות את הסף אם זה מפריע.
- **רענון יומי.** מוצר חדש או מחיר מעודכן יופיעו בחיפוש רק אחרי הבנייה הבאה, או אחרי לחיצה על הכפתור.
- **שפות.** הכללים של סעיפים 6.2, 6.3 ו-6.8 עבריים. על מילים לועזיות פועלים רק הנרמול והחלקים בני שלוש האותיות, וזה מספיק לשגיאות כתיב רגילות ("milwakee").
