# Automated Order Messaging Thread Rules

This platform now auto-creates operational message threads (stored in `chats`) so order collaboration starts without manual inbox setup.

## Auto-thread triggers

1. **Order accepted**
   - Trigger: owner accepts a pending order.
   - Thread participants: **client ↔ owner**.
   - Topic: external order communication.

2. **Staff assigned to order**
   - Trigger: order assignment saved (manual or auto-assignment).
   - Thread participants:
     - **owner ↔ assigned staff** (primary internal execution thread)
     - **owner ↔ other active internal members (HR/staff)** for same-shop visibility, when applicable.

3. **Client-owner discussion required**
   - Trigger: client action that requires discussion (revision request, proof rejection, quote rejection, negotiation).
   - Thread participants: **client ↔ owner**.

4. **QC issue requiring rework**
   - Trigger: QC failure recorded.
   - Thread participants:
     - **owner ↔ assigned staff** for rework coordination.
     - **owner ↔ other active internal members (HR/staff)** for internal visibility.

## Deduplication

- Threads are keyed by:
  - `order_id`
  - normalized participant pair
- Stored in `chats.thread_key` as `order:{order_id}|participants:{sorted_user_ids}`.
- If a thread already exists for the same order + participant pair, the system **reuses** it instead of opening another one.

## Order linkage

- Auto thread entries include `chats.order_id` when the event is order-driven.
- The inbox UI continues to work as before, while message metadata now shows the linked order id when present.

## Permissions model

- External communication is constrained to **client-owner pairs** tied to the order/shop.
- Internal operational communication is constrained to **owner-HR/staff** members inside the same business/shop.
- Existing inbox access controls remain in place.

## Notifications

- Auto-thread events also generate in-app notifications through existing notification utilities (`notify_in_app_participants`).
- Notifications are deduplicated by recent-message rules already used by the notification system.
