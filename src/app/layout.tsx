import type { Metadata, Viewport } from "next";
import { Inter, Playfair_Display } from "next/font/google";
import { ThemeProvider } from "@/components/providers/ThemeProvider";
import { GoogleAnalytics } from "@/components/seo/GoogleAnalytics";
import { JsonLd } from "@/components/seo/JsonLd";
import { organizationLd, websiteLd } from "@/lib/seo/jsonld";
import "./globals.css";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const playfair = Playfair_Display({ subsets: ["latin"], variable: "--font-playfair", display: "swap" });

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com"),
  title: {
    default: "MoneyPuran — Global Financial Intelligence",
    template: "%s | MoneyPuran",
  },
  description:
    "Global markets, business and investing intelligence — stocks, crypto, commodities, forex, central banks and the economy across the US, Europe, Asia and India.",
  applicationName: "MoneyPuran",
  keywords: [
    "global markets", "stock market", "S&P 500", "Nasdaq", "crypto", "bitcoin",
    "gold price", "forex", "EUR USD", "Federal Reserve", "inflation", "earnings",
    "Nifty 50", "Sensex", "business news", "investing",
  ],
  authors: [{ name: "MoneyPuran" }],
  creator: "MoneyPuran",
  openGraph: {
    type: "website",
    locale: "en_US",
    alternateLocale: ["en_GB", "en_IN"],
    siteName: "MoneyPuran",
    title: "MoneyPuran — Global Financial Intelligence",
    description: "Global markets, business & investing.",
    images: [{ url: "/og-image.jpg", width: 1200, height: 630 }],
  },
  twitter: { card: "summary_large_image", site: "@moneypuran", creator: "@moneypuran" },
  robots: { index: true, follow: true, googleBot: { index: true, follow: true, "max-image-preview": "large", "max-snippet": -1 } },
  manifest: "/manifest.json",
  icons: { icon: "/favicon.ico", apple: "/apple-icon.png" },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: [{ media: "(prefers-color-scheme: light)", color: "#ffffff" }, { media: "(prefers-color-scheme: dark)", color: "#0b0f14" }],
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.variable} ${playfair.variable} font-sans antialiased`}>
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
          {children}
        </ThemeProvider>
        <JsonLd data={[organizationLd(), websiteLd()]} />
        {process.env.NEXT_PUBLIC_GA_ID && <GoogleAnalytics gaId={process.env.NEXT_PUBLIC_GA_ID} />}
      </body>
    </html>
  );
}
