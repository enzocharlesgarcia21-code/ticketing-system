# Ticketing App

## Previewing the Employee Dashboard

Run the local XAMPP Apache server, then open:

```text
http://localhost/ticketing/employee/dashboard.php
```

For the dashboard mobile layout, check browser responsive widths around 360px, 375px, 412px, and 428px. The header should fill the full viewport without a blank right-side area. The summary cards use a compact carousel with arrows, swipe, keyboard arrow navigation, and live slide announcements. The carousel CSS keeps the viewport clipped and the track unpadded to avoid cropped white artifacts during slide movement.

The focused files for this pass are:

- `employee/dashboard.php`
- `css/dashboard-carousel.css`
- `js/dashboard-carousel.js`
- `README.md`
