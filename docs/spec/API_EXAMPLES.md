# Trainingsapp - API voorbeelden v1.0

Deze voorbeelden zijn illustratief. Het formele contract staat in `openapi.yaml`.

## 1. Login

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.nl",
  "password": "..."
}
```

```json
{
  "data": {
    "user": {
      "id": "01K...",
      "name": "Gebruiker",
      "email": "user@example.nl",
      "timezone": "Europe/Amsterdam"
    },
    "csrf_token": "..."
  }
}
```

De response zet daarnaast `training_session` als HttpOnly cookie.

## 2. Onboarding ophalen

```http
GET /api/v1/me/today
Cookie: training_session=...
```

```json
{
  "data": {
    "type": "onboarding",
    "program": {
      "id": "3V5FV62M51FNCP7MEBFSJWJBAY",
      "name": "Persoonlijk 14-weken trainingsprogramma",
      "version": "1.0",
      "original_weeks": 14
    }
  }
}
```

## 3. Programma starten vanaf week 6 donderdag

```http
POST /api/v1/me/program/start
Cookie: training_session=...
X-CSRF-Token: ...
Content-Type: application/json

{
  "start_mode": "position",
  "original_week": 6,
  "day_sequence": 4
}
```

De eerste recommendation is blok 2, cyclus 3, positie 4.

## 4. Normale workout action

```json
{
  "data": {
    "type": "workout",
    "reason": "Eerstvolgende openstaande training in je programmavolgorde.",
    "assignment": {
      "id": "01K...",
      "cycle": 2,
      "position": 3,
      "day_label": "woensdag",
      "program_week": 5,
      "status": "pending",
      "is_extra_cycle": false,
      "block": {
        "id": "7R2RZQEKKJ3KPWZ9SYEACCZWJ2",
        "name": "Trainingsblok 2",
        "sequence": 2,
        "original_week_start": 4,
        "original_week_end": 6,
        "default_cycle_count": 3,
        "target_cycles": 3,
        "status": "active"
      },
      "workout": {
        "id": "01K...",
        "name": "Core Control",
        "category": "abs",
        "category_label": "Abs",
        "protocol_type": "amrap",
        "video_url": "https://youtu.be/iFyGjBFdiwc"
      }
    },
    "progress": {
      "block_number": 2,
      "block_count": 5,
      "cycle": 2,
      "target_cycles": 3,
      "processed": 8,
      "total": 18,
      "completed": 8,
      "skipped": 0,
      "pending": 10
    }
  }
}
```

## 5. Afwijkende workout afronden

Stel dinsdag is aanbevolen, maar de gebruiker doet donderdag.

```http
POST /api/v1/workout-assignments/{donderdag-id}/complete
X-CSRF-Token: ...
```

De backend retourneert in `next_action`:

```json
{
  "type": "continuation_decision",
  "source_assignment": {
    "id": "01K...",
    "cycle": 1,
    "position": 4,
    "day_label": "donderdag",
    "status": "completed"
  },
  "options": [
    "program_sequence",
    "last_workout_sequence"
  ]
}
```

## 6. Verder vanaf afwijkende workout

```http
POST /api/v1/me/program/continuation
X-CSRF-Token: ...
Content-Type: application/json

{
  "mode": "last_workout_sequence"
}
```

Daarna is vrijdag de recommendation. Eerdere dinsdag/woensdag blijven pending.

## 7. Terug naar programmavolgorde

```json
{
  "mode": "program_sequence"
}
```

Daarna wordt de oudste nog openstaande assignment aanbevolen.

## 8. Block decision

```json
{
  "data": {
    "type": "block_decision",
    "block": {
      "id": "0J6147BJFBEFFJK2CH4114053K",
      "name": "Trainingsblok 1",
      "sequence": 1,
      "original_week_start": 1,
      "original_week_end": 3,
      "default_cycle_count": 3,
      "target_cycles": 3,
      "status": "decision_required"
    },
    "summary": {
      "completed": 17,
      "skipped": 1,
      "cycles_processed": 3
    },
    "options": [
      "extend",
      "advance"
    ]
  }
}
```

## 9. Extra cyclus toevoegen

```http
POST /api/v1/me/program/blocks/{block-id}/extend
X-CSRF-Token: ...
```

Backend verhoogt target_cycles 3 -> 4 en maakt exact zes nieuwe assignments met `is_extra_cycle=true`.

## 10. Historie

```http
GET /api/v1/me/history?page=1&per_page=25
```

```json
{
  "data": [
    {
      "event_type": "completed",
      "occurred_at": "2026-08-17T19:30:00Z",
      "workout": {
        "id": "01K...",
        "name": "Fast and Sweaty",
        "category": "full_body_hiit",
        "category_label": "Full body HIIT",
        "protocol_type": "amrap",
        "video_url": "https://youtu.be/kNgPYQz8mZA"
      },
      "cycle": 1,
      "assignment_id": "01K...",
      "session_id": "01K...",
      "notes": null
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 1,
    "last_page": 1
  }
}
```
