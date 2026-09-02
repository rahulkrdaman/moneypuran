// Run inside wp-admin via wp.apiFetch. Creates/updates the policy pages.
(async () => {
  const UPDATED = 'Last updated: 2 September 2026';

  const privacy = `
<p><em>${UPDATED}</em></p>
<p>This Privacy Policy explains how <strong>MoneyPuran</strong> ("MoneyPuran", "we", "us") collects, uses and shares information when you visit <a href="https://moneypuran.com/">moneypuran.com</a> (the "Site"). MoneyPuran is an independent business and financial news publication operated by Rahul Kumar, based in India. Questions: <a href="https://moneypuran.com/contact/">contact us</a> or email <a href="mailto:privacy@moneypuran.com">privacy@moneypuran.com</a>.</p>

<h2>1. Information we collect</h2>
<ul>
<li><strong>Information you give us</strong> — your email address when you subscribe to our newsletter; your name, email and message when you use the contact form.</li>
<li><strong>Information collected automatically</strong> — like most websites, our servers and our providers automatically record log data such as your IP address, browser type, referring pages, pages viewed and timestamps. Cookies and similar technologies (see below) may store a device or browser identifier.</li>
<li>We do <strong>not</strong> ask for or knowingly collect financial account numbers, government IDs, or other sensitive personal data.</li>
</ul>

<h2>2. Cookies and similar technologies</h2>
<p>Cookies are small files stored on your device. Web beacons, pixels, local storage and similar technologies work in a comparable way. We and our partners use them to keep the Site working, remember your preferences (such as light/dark mode), measure traffic, and — where you consent — to serve and measure advertising.</p>
<p>Third parties, including Google, may place and read cookies on your browser, or use web beacons or IP addresses, to collect information as a result of ads served on the Site. You can control cookies through your browser settings and through the consent choices described in section 4.</p>

<h2>3. Advertising</h2>
<p>We use <strong>Google AdSense</strong> to display advertising on the Site, and we may use other advertising partners in future.</p>
<ul>
<li>Third-party vendors, including Google, use cookies to serve ads based on your prior visits to this and other websites.</li>
<li>Google's use of advertising cookies enables it and its partners to serve ads to you based on your visit to the Site and/or other sites on the Internet.</li>
<li>You may opt out of personalised advertising by visiting <a href="https://adssettings.google.com/" rel="nofollow noopener" target="_blank">Google Ads Settings</a>. You can also opt out of a third party's use of cookies for personalised advertising at <a href="https://www.aboutads.info/choices/" rel="nofollow noopener" target="_blank">aboutads.info/choices</a> and, in Europe, <a href="https://www.youronlinechoices.eu/" rel="nofollow noopener" target="_blank">youronlinechoices.eu</a>.</li>
<li>For more information about how Google uses data when you use our partners' sites or apps, see <a href="https://policies.google.com/technologies/partner-sites" rel="nofollow noopener" target="_blank">How Google uses information from sites or apps that use our services</a>.</li>
</ul>
<p>Ads on the Site are clearly labelled "Advertisement". See our <a href="https://moneypuran.com/advertising-disclosure/">Advertising Disclosure</a> for how advertising is kept separate from editorial content.</p>

<h2>4. Consent (EEA, UK and Switzerland)</h2>
<p>Where required by law, we ask for your consent before setting non-essential cookies or using your data for personalised advertising and analytics. We use a consent management platform to record your choice; by default, advertising and analytics storage are set to "denied" until you choose otherwise. You can change or withdraw your consent at any time using the privacy settings link provided by that tool. Essential cookies needed to operate the Site do not require consent.</p>

<h2>5. Analytics</h2>
<p>We may use privacy-respecting analytics (such as Google Analytics) to understand how the Site is used, in aggregate. Where Google Analytics is used, IP addresses are truncated/anonymised where that option is available, and analytics storage is subject to your consent choice. You can opt out with the <a href="https://tools.google.com/dlpage/gaoptout" rel="nofollow noopener" target="_blank">Google Analytics Opt-out Browser Add-on</a>.</p>

<h2>6. How we use information</h2>
<ul>
<li>To operate, maintain and secure the Site and prevent abuse.</li>
<li>To send the newsletter you asked for (each email includes an unsubscribe link).</li>
<li>To respond to your enquiries.</li>
<li>To measure and improve our content and, with your consent, to show and measure advertising.</li>
<li>To comply with law and enforce our <a href="https://moneypuran.com/terms/">Terms of Use</a>.</li>
</ul>

<h2>7. How information is shared</h2>
<p>We do not sell your personal information for money. We share information only with:</p>
<ul>
<li><strong>Service providers</strong> that host the Site (Hostinger), deliver our newsletter, and provide analytics and advertising (Google), acting on our instructions.</li>
<li><strong>Advertising partners</strong> as described in section 3, subject to your consent.</li>
<li><strong>Legal</strong> — where we believe disclosure is required by law or to protect rights, safety or property.</li>
</ul>

<h2>8. Your rights</h2>
<p>Depending on where you live, you may have the right to access, correct, delete, or port your personal data, to object to or restrict certain processing, and to withdraw consent. To exercise these rights, email <a href="mailto:privacy@moneypuran.com">privacy@moneypuran.com</a>. You may also complain to your local data protection authority.</p>
<p><strong>California residents:</strong> we do not "sell" personal information as that term is commonly understood. To the extent sharing data with advertising partners for cross-context behavioural advertising is a "sale" or "share" under California law, you can opt out using the consent tool described in section 4 or your browser's Global Privacy Control signal, which we honour where technically feasible.</p>

<h2>9. Children</h2>
<p>The Site is intended for a general adult audience and is not directed to children under 13 (or the equivalent minimum age in your country). We do not knowingly collect personal information from children, and we do not use interest-based advertising to target children. If you believe a child has provided us personal data, contact us and we will delete it.</p>

<h2>10. Data retention</h2>
<p>Newsletter subscriptions are kept until you unsubscribe. Contact-form messages are kept for up to 24 months. Server logs are kept for a short period for security and diagnostics. Advertising and analytics providers retain data according to their own policies.</p>

<h2>11. International transfers</h2>
<p>Our providers, including Google and our host, may process data in countries other than yours, including the United States. Where required, transfers rely on appropriate safeguards such as standard contractual clauses.</p>

<h2>12. Security</h2>
<p>We use HTTPS across the Site and take reasonable technical and organisational measures to protect information. No method of transmission or storage is completely secure.</p>

<h2>13. Changes</h2>
<p>We may update this policy from time to time. Material changes will be noted by updating the date at the top of this page.</p>

<h2>14. Contact</h2>
<p>MoneyPuran — Rahul Kumar, India. Email: <a href="mailto:privacy@moneypuran.com">privacy@moneypuran.com</a>. See also our <a href="https://moneypuran.com/contact/">Contact</a> page.</p>
`;

  const advertising = `
<p><em>${UPDATED}</em></p>
<p>This page explains how advertising works on <strong>MoneyPuran</strong> and how we keep it separate from our journalism.</p>

<h2>Advertising networks</h2>
<p>We display advertising through <strong>Google AdSense</strong>. We may add other reputable advertising partners in future and will update this page if we do. Advertising helps fund our reporting and keeps MoneyPuran free to read.</p>

<h2>How ads are labelled and placed</h2>
<ul>
<li>Every ad unit is clearly labelled <strong>"Advertisement"</strong> and is visually separated from editorial content.</li>
<li>We do not place ads in a way that interferes with reading, that sits directly against buttons or navigation, or that you cannot dismiss.</li>
<li>We do not use pop-ups, auto-playing video ads with sound, prestitial ads with countdown timers, or other formats that fail the <a href="https://www.betterads.org/standards/" rel="nofollow noopener" target="_blank">Better Ads Standards</a>.</li>
<li>Ads are not shown on search results, error pages, or thin utility pages.</li>
</ul>

<h2>Cookies and your choices</h2>
<p>Google and other third-party vendors use cookies to serve ads based on your prior visits to this and other websites. You can manage this:</p>
<ul>
<li>In the EEA, UK and Switzerland, through the consent prompt shown on your first visit (you can reopen it any time from the privacy settings link).</li>
<li>Anywhere, via <a href="https://adssettings.google.com/" rel="nofollow noopener" target="_blank">Google Ads Settings</a>, <a href="https://www.aboutads.info/choices/" rel="nofollow noopener" target="_blank">aboutads.info/choices</a> or <a href="https://www.youronlinechoices.eu/" rel="nofollow noopener" target="_blank">youronlinechoices.eu</a>.</li>
</ul>
<p>See our <a href="https://moneypuran.com/privacy-policy/">Privacy Policy</a> for full detail, including the link to <a href="https://policies.google.com/technologies/partner-sites" rel="nofollow noopener" target="_blank">How Google uses information from sites or apps that use our services</a>.</p>

<h2>Editorial independence</h2>
<p>Advertisers have no influence over what we cover or how we cover it. Our newsroom does not know which advertisers are running on the Site. Ad performance never affects editorial decisions.</p>

<h2>Sponsored content and affiliate links</h2>
<p>If we ever publish sponsored or paid content, it will be clearly labelled as "Sponsored" or "Paid partnership" and will not be presented as independent reporting. If an article contains affiliate links (links that may earn us a commission), that will be disclosed within the article. Affiliate relationships never change our editorial assessment.</p>

<h2>ads.txt</h2>
<p>We maintain an <a href="https://moneypuran.com/ads.txt">ads.txt</a> file listing the companies authorised to sell advertising on this domain, to help prevent counterfeit inventory.</p>

<h2>Contact</h2>
<p>Advertising questions or complaints about a specific ad: <a href="mailto:ads@moneypuran.com">ads@moneypuran.com</a>.</p>
`;

  const disclaimer = `
<p><em>${UPDATED}</em></p>

<h2>Not investment advice</h2>
<p><strong>MoneyPuran publishes news, data and general information about markets and business. Nothing on this Site is investment, financial, trading, legal, accounting or tax advice, a recommendation, or an offer or solicitation to buy or sell any security or other financial instrument.</strong> We are not a registered investment adviser, research analyst or broker in any jurisdiction. Content is not tailored to your circumstances.</p>

<h2>Do your own research</h2>
<p>Markets carry risk, including the possible loss of principal. Past performance does not guarantee future results. Before making any financial decision, consult a licensed professional — for example a SEBI-registered investment adviser in India or an appropriately licensed adviser in your country — and read all relevant offer documents.</p>

<h2>Market data</h2>
<p>Prices, index levels, indicative gold and silver rates and other market data on the Site are provided for general information, are sourced from third parties (including Yahoo Finance), and may be <strong>delayed</strong> or inaccurate. Indicative bullion figures are simple currency conversions of exchange prices and exclude taxes, import duty and dealer charges; they are not retail quotes. We do not guarantee the accuracy, completeness or timeliness of any data and are not liable for decisions made in reliance on it.</p>

<h2>Use of AI</h2>
<p>Some MoneyPuran articles are drafted with the assistance of AI tools working from official primary sources (such as regulator and central-bank releases) and are reviewed against our <a href="https://moneypuran.com/editorial-policy/">Editorial Policy</a> before publication. Errors can still occur — please tell us via our <a href="https://moneypuran.com/corrections-policy/">Corrections Policy</a>.</p>

<h2>External links</h2>
<p>Links to third-party websites are provided for convenience and do not constitute an endorsement. We are not responsible for the content, accuracy or practices of external sites.</p>

<h2>Limitation of liability</h2>
<p>The Site and its content are provided "as is" without warranties of any kind. To the fullest extent permitted by law, MoneyPuran and its operator are not liable for any loss or damage arising from your use of, or reliance on, the Site or its content.</p>

<h2>Contact</h2>
<p>Questions about this disclaimer: <a href="https://moneypuran.com/contact/">contact us</a>.</p>
`;

  const terms = `
<p><em>${UPDATED}</em></p>
<p>These Terms of Use govern your access to and use of <a href="https://moneypuran.com/">moneypuran.com</a> (the "Site"), operated by Rahul Kumar ("MoneyPuran", "we"). By using the Site you agree to these Terms. If you do not agree, do not use the Site.</p>

<h2>1. Use of the Site</h2>
<p>You may read and share our content for personal, non-commercial purposes. You may not scrape, copy, republish, redistribute, frame or create derivative works from our content at scale or for commercial purposes without our written permission. You may quote short extracts with clear attribution and a link.</p>

<h2>2. Intellectual property</h2>
<p>All content on the Site — text, graphics, logos and code — is owned by MoneyPuran or its licensors and is protected by intellectual-property laws. The "MoneyPuran" name and logo may not be used without permission. To report claimed copyright infringement, contact <a href="mailto:legal@moneypuran.com">legal@moneypuran.com</a>.</p>

<h2>3. Acceptable use</h2>
<p>You agree not to use the Site to break the law, infringe others' rights, transmit malware, attempt unauthorised access, interfere with the Site's operation, or misrepresent your identity. We may suspend access for violations.</p>

<h2>4. Newsletter and submissions</h2>
<p>If you subscribe to our newsletter, you consent to receive it and can unsubscribe at any time. Any feedback or material you send us may be used by us without obligation or compensation, unless we agree otherwise in writing.</p>

<h2>5. No advice; no warranty</h2>
<p>The Site provides general information only and is not investment, financial, legal or tax advice — see our <a href="https://moneypuran.com/disclaimer/">Disclaimer</a>. The Site and its content are provided "as is" and "as available" without warranties of any kind, express or implied.</p>

<h2>6. Limitation of liability</h2>
<p>To the fullest extent permitted by law, MoneyPuran and its operator will not be liable for any indirect, incidental, special, consequential or punitive damages, or any loss of profits, data or goodwill, arising from your use of the Site.</p>

<h2>7. Indemnity</h2>
<p>You agree to indemnify MoneyPuran against claims arising from your misuse of the Site or breach of these Terms.</p>

<h2>8. Advertising</h2>
<p>The Site carries third-party advertising. Your interactions with advertisers are solely between you and the advertiser. See our <a href="https://moneypuran.com/advertising-disclosure/">Advertising Disclosure</a> and <a href="https://moneypuran.com/privacy-policy/">Privacy Policy</a>.</p>

<h2>9. Changes</h2>
<p>We may modify these Terms or the Site at any time. Continued use after changes means you accept the updated Terms.</p>

<h2>10. Governing law</h2>
<p>These Terms are governed by the laws of India, and the courts of India will have jurisdiction, without prejudice to mandatory consumer-protection rights in your country of residence.</p>

<h2>11. Contact</h2>
<p>MoneyPuran — Rahul Kumar, India. Email: <a href="mailto:legal@moneypuran.com">legal@moneypuran.com</a>.</p>
`;

  async function upsert(slug, title, content) {
    const existing = await wp.apiFetch({path: `/wp/v2/pages?slug=${slug}&status=publish,draft&_fields=id`});
    const body = { title, content, status: 'publish' };
    if (existing.length) {
      const r = await wp.apiFetch({path: `/wp/v2/pages/${existing[0].id}`, method: 'POST', data: body});
      return { slug, action: 'updated', id: r.id, link: r.link };
    }
    const r = await wp.apiFetch({path: '/wp/v2/pages', method: 'POST', data: body});
    return { slug, action: 'created', id: r.id, link: r.link };
  }

  const out = [];
  out.push(await upsert('privacy-policy', 'Privacy Policy', privacy.trim()));
  out.push(await upsert('advertising-disclosure', 'Advertising Disclosure', advertising.trim()));
  out.push(await upsert('disclaimer', 'Disclaimer', disclaimer.trim()));
  out.push(await upsert('terms', 'Terms of Use', terms.trim()));
  return out;
})();
