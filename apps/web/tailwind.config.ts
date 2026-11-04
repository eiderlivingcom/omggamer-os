import type { Config } from "tailwindcss";

export default {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        navy: { 950: "#0c0b1a", 900: "#131126", 800: "#1c1935", 700: "#29254a", 600: "#3a3566" },
        brand: { 50: "#f3efff", 100: "#e7dfff", 400: "#9d7bff", 500: "#7c4dff", 600: "#6a36f0", 700: "#5a28d4" },
        ink: { 900: "#16142a", 700: "#3b3856", 500: "#67647f", 300: "#a7a4bb", 200: "#d9d7e5", 100: "#ecebf3", 50: "#f6f6fa" },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
        mono: ['"JetBrains Mono"', "ui-monospace", "Menlo", "monospace"],
      },
      boxShadow: { card: "0 1px 2px rgba(22,20,42,.04), 0 1px 1px rgba(22,20,42,.03)" },
    },
  },
  plugins: [],
} satisfies Config;
