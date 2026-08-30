// Broad Wikimedia Commons search fallbacks per category, tried in order when a
// post's specific image_query returns nothing (common for abstract finance
// topics — "yield curve", "inflation", "ETF"). Each term reliably returns a
// freely-licensed landscape photo. A featured image is what makes a post
// Discover-eligible, so no post should ever publish without one.

export const CATEGORY_IMAGE_QUERY = {
  "US Markets":       ["New York Stock Exchange building", "Wall Street street sign", "stock market display board"],
  "Indian Markets":   ["Bombay Stock Exchange building", "BSE building Mumbai", "Indian rupee banknotes"],
  "Global Markets":   ["stock exchange trading floor", "financial district skyline", "stock market chart screen"],
  "Stocks":           ["stock market chart screen", "New York Stock Exchange building", "financial newspaper stock pages"],
  "Earnings":         ["corporate office building", "annual report documents", "business meeting boardroom"],
  "IPOs":             ["stock exchange trading floor", "Nasdaq MarketSite Times Square", "company listing ceremony"],
  "Crypto":           ["Bitcoin physical coin", "cryptocurrency mining hardware", "blockchain data centre"],
  "Commodities":      ["gold bars bullion", "oil drilling rig", "oil refinery at night", "copper metal"],
  "Economy":          ["Federal Reserve building Washington", "shipping containers port", "US Capitol building"],
  "Central Banks":    ["Federal Reserve building Washington", "Reserve Bank of India building", "European Central Bank Frankfurt"],
  "Personal Finance": ["piggy bank and coins", "household budget calculator", "retirement savings jar"],
  "Regulation":       ["US Securities and Exchange Commission building", "courthouse columns", "government office building Washington"],
};
