import type { Metadata, Viewport } from "next";
import { Inter, Playfair_Display } from "next/font/google";
import { ThemeProvider } from "@/components/providers/ThemeProvider";
import { GoogleAnalytics } from "@/components/seo/GoogleAnalytics";
import "./globals.css";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const playfair = Playfair_Display({ subsets: ["latin"], variable: "--font-playfair", display: "swap" });

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_APP_URL || "https://moneypuran.com"),
  title: { default: "MoneyPuran - Finance & Business News", template: "%s | MoneyPuran" },
  description: "India's trusted source for finance, business, markets, and economy news.",
  keywords: ["finance news", "business news", "stock market", "economy", "investment"],
  authors: [{ name: "MoneyPuran" }],
  creator: "MoneyPuran",
  openGraph: {
    type: "website",
    locale: "en_IN",
    siteName: "MoneyPuran",
    images: [{ url: "/og-image.jpg", width: 1200, height: 630 }],
  },
  twitter: { card: "summary_large_image", site: "@moneypuran" },
  robots: { index: true, follow: true, googleBot: { index: true, follow: true } },
  manifest: "/manifest.json",
  icons: { icon: "/favicon.ico", apple: "/apple-icon.png" },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: [{ media: "(prefers-color-scheme: light)", color: "#ffffff" }, { media: "(prefers-color-scheme: dark)", color: "#0f172a" }],
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.variable} ${playfair.variable} font-sans antialiased`}>
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
          {children}
        </ThemeProvider>
        {process.env.NEXT_PUBLIC_GA_ID && <GoogleAnalytics gaId={process.env.NEXT_PUBLIC_GA_ID} />}
      </body>
    </html>
  );
}
