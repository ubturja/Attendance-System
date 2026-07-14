/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#EF7F22',
          50: '#fdf6ef',
          100: '#faebd9',
          500: '#EF7F22',
          600: '#d66b15',
        },
      },
    },
  },
  plugins: [],
}
