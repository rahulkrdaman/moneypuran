import type { Config } from "tailwindcss";

const config: Config = {
  darkMode: "class",
  content: [
    "./src/pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/components/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      // ── CSS variable-based semantic tokens (shadcn/ui pattern) ──────────────
      // These map Tailwind utilities to the CSS variables defined in globals.css
      colors: {
        background:  "hsl(var(--background))",
        foreground:  "hsl(var(--foreground))",
        border:      "hsl(var(--border))",
        input:       "hsl(var(--input))",
        ring:        "hsl(var(--ring))",
        card: {
          DEFAULT:    "hsl(var(--card))",
          foreground: "hsl(var(--card-foreground))",
        },
        muted: {
          DEFAULT:    "hsl(var(--muted))",
          foreground: "hsl(var(--muted-foreground))",
        },
        primary: {
          DEFAULT:    "hsl(var(--primary))",
          foreground: "hsl(var(--primary-foreground))",
        },
        secondary: {
          DEFAULT:    "hsl(var(--secondary))",
          foreground: "hsl(var(--secondary-foreground))",
        },
        accent: {
          DEFAULT:    "hsl(var(--accent))",
          foreground: "hsl(var(--accent-foreground))",
        },
        destructive: {
          DEFAULT:    "hsl(var(--destructive))",
          foreground: "hsl(var(--destructive-foreground))",
        },
        popover: {
          DEFAULT:    "hsl(var(--popover))",
          foreground: "hsl(var(--popover-foreground))",
        },

        // ── Brand orange palette ──────────────────────────────────────────────
        brand: {
          50:  "#fff7ed",
          100: "#ffedd5",
          200: "#fed7aa",
          300: "#fdba74",
          400: "#fb923c",
          500: "#f97316",
          600: "#ea580c",
          700: "#c2410c",
          800: "#9a3412",
          900: "#7c2d12",
        },

        // ── Finance-specific colours ──────────────────────────────────────────
        finance: {
          green: "#16a34a",
          red:   "#dc2626",
          gold:  "#d97706",
          blue:  "#2563eb",
          navy:  "#1e3a5f",
        },
      },

      borderRadius: {
        lg: "var(--radius)",
        md: "calc(var(--radius) - 2px)",
        sm: "calc(var(--radius) - 4px)",
      },

      fontFamily: {
        sans:    ["var(--font-inter)",     "system-ui", "sans-serif"],
        serif:   ["var(--font-playfair)",  "Georgia",   "serif"     ],
        mono:    ["var(--font-mono)",      "monospace"               ],
        heading: ["var(--font-playfair)",  "Georgia",   "serif"     ],
      },

      animation: {
        "fade-in":    "fadeIn 0.3s ease-in-out",
        "slide-up":   "slideUp 0.4s ease-out",
        "slide-down": "slideDown 0.3s ease-out",
        "ticker":     "ticker 30s linear infinite",
        "pulse-slow": "pulse 3s cubic-bezier(0.4,0,0.6,1) infinite",
      },

      keyframes: {
        fadeIn:    { from: { opacity: "0" },                               to: { opacity: "1" }                               },
        slideUp:   { from: { opacity: "0", transform: "translateY(16px)"  }, to: { opacity: "1", transform: "translateY(0)"  } },
        slideDown: { from: { opacity: "0", transform: "translateY(-16px)" }, to: { opacity: "1", transform: "translateY(0)"  } },
        ticker:    { "0%": { transform: "translateX(100%)" },               "100%": { transform: "translateX(-100%)" }         },
      },
    },
  },
  plugins: [
    require("@tailwindcss/typography"),
    // Note: line-clamp is built into Tailwind CSS v3.3+ — no plugin needed
  ],
};

export default config;
