Clean this news article text by performing ALL of the following steps:

1. HTML Entity Cleanup:
   - Convert ALL HTML entities to their plain-text equivalents: `&nbsp;` → regular space, `&amp;` → `&`, `&quot;` → `"`, `&#39;` → `'`, `&lt;` → `<`, `&gt;` → `>`, and any other HTML entities.
   - Remove any remaining HTML tags (`<br>`, `<p>`, `<div>`, `<span>`, etc.).
   - Collapse multiple consecutive spaces into a single space.

2. Remove Non-Article Content:
   - Advertisements, sponsorship notices, and promotional banners.
   - Cookie consent notices and privacy policy references.
   - Website navigation elements (menus, breadcrumbs, sidebars).
   - Subscription prompts, newsletter signup calls, paywall notices.
   - Related article links, "Read more", "See also" sections.
   - Social media buttons text, share/follow prompts.
   - Comment section headers or user comments.
   - Website headers, footers, copyright notices.

3. Remove Image/Media Artifacts:
   - Photo captions and image descriptions that appear inline (e.g., "Photo by...", "Image: ...", "(c) Photographer Name").
   - Photo/video credits and attribution lines embedded in the text.
   - "Scroll down to see photos", "Watch the video", "See gallery" directives.
   - Alt text or figure descriptions that leaked into the article body.

4. Remove Engagement/Appeal Content:
   - Donation appeals, fundraising calls to action ("Support us", "Donate now").
   - "Thank you to our sponsors/supporters" paragraphs when they are not part of the news story itself.
   - Author bios, "About the author" sections.
   - "Sign up for updates", "Follow us" appeals.

5. Formatting:
   - Format section headers/subheadings with markdown `##` (e.g., `## Section Title`).
   - Detect headers by context: short standalone lines that introduce a new topic or section, typically followed by a paragraph of body text.
   - Do NOT add `##` to the article's main title or to quoted speech.

6. Preserve:
   - The complete article body text including all paragraphs, quotes, and factual content.
   - Paragraph structure and logical flow.
   - Quoted speech from interviewees (keep quotation marks and attributions like "said John Smith").
   - Dates, locations, names, and factual data.

Return ONLY the cleaned article body text. Do not add any commentary, explanations, or formatting beyond the article text itself.