# Staff mobile API (`/api/staff/*`)

API of the staff app (`mobileemployee/`, Flutter). It does what the web panel does, with the same
services, validation and role rules; a token belongs to one club.

- Base URL: `https://zafiro.kz/api`. Headers: `Authorization: Bearer <token>`, `Accept: application/json`.
- Times are UTC ISO 8601 unless noted. Money is a string with two decimals (`"1500.00"`) unless noted.
- Errors: `401` token expired (sign in again), `402` subscription inactive, `403` role not allowed
  (`{message}`), `404`, `422` validation (`{message, errors: {field: [..]}}`), `429` too many requests.

## Roles and permissions

Staff roles: `owner`, `admin`, `manager`, `cashier`, `operator`. A customer (`client`) gets `403` on
every `/staff` endpoint. `GET /staff/me` returns `permissions` — the app shows only what is allowed:

| permission | roles | what |
|---|---|---|
| `cash` | owner, admin, manager, cashier | cash register: shift, top-ups, sales, cash in/out |
| `guest_session` | owner, admin, manager, cashier (and guest mode on) | time for a guest on PCs |
| `end_session` | owner, admin, manager, operator | end a customer's session on a PC |
| `control` | all staff | reserve / power on / restart / power off PCs |
| `chat_reply` | cashier, operator | answer customers in the chat (others read only) |
| `stock_receive` | all staff | receive goods |
| `stock_manage` | owner, admin, manager | receive and write off goods |
| `reports` | owner, admin, manager | day report |

## Sign-in (existing endpoints)

- `POST /auth/login` `{phone: "+77001234567", password, device_name: "Zafiro Staff"}` →
  `{token, expires_at, user: {id, name, phone, role, currency}, club_id, club_name, clubs: [{id, name, current, role}]}`.
  The token is for `club_id`. If `user.role` is not a staff role but `clubs` has a club with a staff
  role, call switch-club for it; with no staff club show "Нет доступа сотрудника".
- `POST /auth/switch-club` `{club_id}` → same shape, a new token (the old one stops working).
- `POST /auth/logout`.
- Forgot password: `POST /auth/password/code` `{phone}` → `{message}`; `POST /auth/password/reset`
  `{phone, code, password, password_confirmation}` → `{message}`.

## `GET /staff/me`

```json
{
  "user": {"id": 1, "name": "Арман", "phone": "+77001234567"},
  "club": {"id": 5, "name": "Cyber Arena", "currency": "KZT", "timezone": "Asia/Almaty"},
  "role": "cashier",
  "permissions": {"cash": true, "guest_session": true, "end_session": false, "control": true, "chat_reply": true, "stock_receive": true, "stock_manage": false, "reports": false},
  "counters": {"orders_new": 2, "chat_unread": 1, "notifications_unread": 3},
  "shift": {"id": 12, "opened_at": "2026-10-19T08:00:00Z", "balance": "15000.00"}
}
```
`shift` is `null` when no cash shift is open (or `cash` is not allowed).

## Club map / PCs

### `GET /staff/computers`
```json
{
  "zones": [{"id": 1, "name": "VIP", "price_per_hour": "600.00"}],
  "computers": [{
    "id": 7, "number": 21, "name": "PC 21", "zone_id": 1,
    "status": "online",          // online | offline | reserved | restarting (currentStatus)
    "connection": "online",      // online | offline (heartbeat in the last 90 s)
    "agent_state": "in_session", // idle | in_session | locked | shutting_down | null
    "reserved": false, "booked_now": false,
    "session": {"id": "uuid", "ends_at": "2026-10-19T12:30:00Z", "guest": false, "user": {"id": 9, "name": "Ерлан"}},
    "staff_visit": {"until": "2026-10-19T10:15:00Z", "name": "Админ"}
  }],
  "guest_mode": true,
  "tariffs": [{"id": 3, "name": "1 час", "minutes": 60, "price": "800.00", "zone_ids": [1]}]
}
```
`session` and `staff_visit` are `null` when there is none.

### `POST /staff/computers/control` (permission `control`)
`{computer_ids: [7, 8], action: "reserve" | "power_on" | "restart" | "power_off"}` →
`{updated, status, queued, message}` for `restart` / `power_off` (the agent takes the command with
its next heartbeat), `{updated, status: null, woken, without_mac, relays, message}` for `power_on`
(Wake-on-LAN sent by the club's running PCs), or, for `reserve` (toggles),
`{updated, reserved, computers: [{id, status}]}`. Show `message` to the staff member.

### `POST /staff/computers/guest-session` (permission `guest_session`, needs an open shift)
`{computer_ids: [7], tariff_id: 3}` or `{computer_ids: [7], minutes: 90}`, plus `payment_method: cash | card | qr` →
`{message, amount, computers: [{id, ends_at: "HH:MM"}]}`.

### `POST /staff/computers/{id}/end-session` (permission `end_session`)
`{session_id}` (from `computers[].session.id`) → `{refunded, refunded_bonus, kept_minutes}`.

## Cash register (permission `cash`)

### `GET /staff/cash`
```json
{
  "currency": "KZT",
  "shift": {"id": 12, "opened_at": "...", "opening_balance": "10000.00"},
  "totals": {"income": "5000.00", "expense": "0.00", "balance": "15000.00"},
  "report": {
    "kinds": [{"label": "Продажа товаров", "count": 3, "amount": "1500.00"}],
    "items": [{"name": "Cola", "quantity": 4, "amount": "1400.00"}],
    "items_total": "1400.00", "discount": "0.00",
    "staff": [{"name": "Арман", "count": 5, "income": "5000.00", "expense": "0.00"}]
  },
  "transactions": [{"id": 101, "type": "income", "amount": "750.00", "description": "Пополнение …", "at": "..."}]
}
```
With no open shift: `shift`, `totals`, `report` are `null`, `transactions` is `[]`. `transactions` — the last 20 of the shift.

- `POST /staff/cash/shifts` `{opening_balance}` → `{shift}`.
- `POST /staff/cash/shifts/{id}/close` → `{shift: {id, closed_at, closing_balance}, totals, report}` — the Z-report of the closed shift.
- `POST /staff/cash/top-ups` `{user_id, amount, balance_type: balance | bonus_balance, payment_method: cash | card | qr}` →
  `{message, bonus, balance, bonus_balance}` (`bonus` — the top-up bonus added, `"0.00"` when none).
- `POST /staff/cash/sales` `{items: [{product_id, quantity}]}` → `{id, amount, items: [{name, quantity, price, total}]}`.
- `POST /staff/cash/transactions` `{type: income | expense, amount, description}` → `{id, type, amount, description}`.

## Customers (all staff)

### `GET /staff/customers?search=арман&page=1`
Search by name or phone (≥ 2 characters; digits of a phone with 8/7 at the start both match). 25 a page.
```json
{"data": [{"user_id": 9, "name": "Ерлан", "phone": "+77011112233", "balance": "500.00", "bonus_balance": "50.00",
           "discount": "10.00", "discount_cumulative": true, "total_spent": "35000.00"}],
 "meta": {"current_page": 1, "last_page": 3}}
```

## Goods (all staff)

- `GET /staff/products?search=cola&page=1` → `{data: [{id, sku, name, category, price, quantity, image_url}], meta}` (50 a page, by category and name).
- `POST /staff/stock/receive` (permission `stock_receive`) `{product_id, quantity, note?}` → `{product_id, quantity}` (new stock).
- `POST /staff/stock/movements` (permission `stock_manage`) `{product_id, type: receipt | write_off, quantity, note?}` → `{product_id, quantity}`.

## Orders from the PC agent (all staff)

- `GET /staff/orders?status=new|done|cancelled|all&page=1` →
  `{data: [{id, status, computer_name, customer: {id, name, phone}, items: [{name, quantity, price, total}], amount, created_at, handled_by, handled_at}], meta, new_count}` (30 a page, newest first).
- `POST /staff/orders/{id}/done` and `POST /staff/orders/{id}/cancel` → the order. `422` (`errors.order`) when it is no longer new.

## Chat (all staff read; `chat_reply` answers)

- `GET /staff/chats` → `{conversations: [{customer: {id, name, phone}, unread, last: {id, body, from_staff, at}}], unread}`.
- `GET /staff/chat/poll?customer=9&after=0` → `{messages: [{id, from_staff, author, computer, body, at: "dd.mm HH:MM"}], unread}`.
  Opening a conversation marks it read for cashiers and operators.
- `POST /staff/chat/{customer}` `{body}` (≤ 1000) → `201 {message: {...}}`.

## Notifications (all staff)

- `GET /staff/notifications` → `{unread_count, items: [{id, icon, title, body, url, at, unread, at_human}]}`.
- `POST /staff/notifications/seen` → `{unread_count: 0}`.

## Day report (permission `reports`)

### `GET /staff/reports/today`
```json
{
  "currency": "KZT",
  "today": {"income": "45000.00", "expense": "2000.00", "transactions": 37},
  "week": [{"date": "2026-10-13", "income": "40000.00", "expense": "1000.00"}],
  "computers": {"total": 10, "online": 8, "busy": 5, "reserved": 1},
  "low_stock": [{"id": 4, "name": "Cola", "quantity": 3}],
  "recent_transactions": [{"id": 101, "type": "income", "amount": "750.00", "description": "…", "at": "..."}]
}
```
Days are the club's local days.
