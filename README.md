# TAP Werbeagentur — Agency Website, CMS & Digital Tools

![PHP](https://img.shields.io/badge/PHP-8-777BB4?logo=php&logoColor=white) ![Firebase](https://img.shields.io/badge/Firebase-RTDB-FFCA28?logo=firebase&logoColor=black)

> Website of an advertising agency, plus its own tools: digital business cards, a restaurant menu creator, link pages, page templates and a CMS.

## Overview
A production project built and deployed by **Mahmoud Hasan**. This repository holds the source code only. The client's secrets,
databases, uploaded files and personal data are not included; where the code needs them, they are replaced with placeholders.

**Tech stack:** HTML5 · Vanilla JS · PHP 8 · Firebase Realtime DB · Gemini API (optional)

## Features
- Agency website: Werbetechnik, Digitaldruck, Textildruck, Grafikdesign, Webdesign portfolio
- Digital business card editor with four templates (dark, white, prince, softcut) and a gallery
- Menu creator for restaurants (upload, edit, view)
- Link-in-bio pages; landing page templates for café, barber, pharmacy, restaurant and shop
- CMS (`cms.js`, `api/content.php`) with login and image upload
- Customer area and team area

## Project structure
```
index.html               agency homepage
*-editor.html / card-*    business card tools
menu-*.html              menu creator
template-*.html          landing page templates
api/                     PHP API + content
dashboard/  team/        admin and team areas
data/content.json        site content
```

## Getting started
Upload to a PHP 8 host. Before use, replace the placeholders with your own values: Firebase project config (`YOUR_FIREBASE_API_KEY`), Gemini key, admin passwords (`CHANGE_ME`).

## Security & privacy
- No credentials in the repository. API keys, passwords and database settings are placeholders (`CHANGE_ME`, `YOUR_…`, `your_db_password`).
- Runtime data (customers, messages, appointments, logs, uploads) is left out.
- Personal names, phone numbers and addresses in the default content are replaced with sample data (e.g. Max Mustermann, +49 151 00000000, Musterstraße 1).

## Author
**Mahmoud Hasan** · [GitHub](https://github.com/MahmoudHasan2)
