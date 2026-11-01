# -*- coding: utf-8 -*-
"""
Генератор интерактивной документации REST API для проекта «Семейная Аптечка»:
1. OpenAPI 3.1.0 спецификация (openapi.json и openapi.yaml)
2. Postman Collection v2.1.0 (FamilyApteka.postman_collection.json)
3. Таблица спецификации API по ГОСТ 7.32 (API_СПЕЦИФИКАЦИЯ_ГОСТ.md)
"""

import json
import yaml
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PUBLIC_DIR = os.path.join(BASE_DIR, 'public')
ROOT_DIR = os.path.abspath(os.path.join(BASE_DIR, '..', '..'))
DOCS_GOST_DIR = os.path.join(ROOT_DIR, 'Документация', '01_НИР_и_Практики', '3_Семестр', 'Разработка и реализация программных систем защиты информации')

os.makedirs(PUBLIC_DIR, exist_ok=True)
os.makedirs(DOCS_GOST_DIR, exist_ok=True)

# --------------------------------------------------------------------------
# 1. ОБЩИЕ СХЕМЫ И ДАННЫЕ OPENAPI 3.1
# --------------------------------------------------------------------------

def build_openapi_spec():
    spec = {
        "openapi": "3.1.0",
        "info": {
            "title": "REST API «Семейная Аптечка» (Family Medicine Cabinet)",
            "version": "1.0.0",
            "description": (
                "## Защищенный REST API веб-приложения «Семейная аптечка»\n\n"
                "Система предназначена для персонального и коллективного учёта лекарственных препаратов, "
                "контроля сроков годности (включая модель FEFO-Opened после вскрытия упаковки), "
                "составления расписаний курсов приёма и автоматизированной клинической поддержки принятия решений (CDS).\n\n"
                "### Архитектурные механизмы информационной безопасности:\n"
                "- **Аутентификация:** Токены доступа Laravel Sanctum (Bearer токен в заголовке `Authorization: Bearer <token>`).\n"
                "- **Криптографическая защита Data-at-Rest:** Пароли пользователей хешируются с использованием алгоритма **Argon2id** (RFC 9106, memory-hard). "
                "Чувствительные медицинские поля (`notes`, `custom_trade_name`) шифруются симметричным шифром **AES-256-CBC**.\n"
                "- **Авторизация и противодействие IDOR:** Контроль доступа на уровне политик (Laravel Policies: `MedicineBoxPolicy`, `IntakeCoursePolicy`) "
                "и ролевая модель RBAC/ABAC (`owner`, `editor`, `viewer`).\n"
                "- **Защита от сетевых атак и перебора:** Дросселирование запросов (`throttle:login` — 5 попыток/мин на аутентификацию, `throttle:60,1` — защита каталога ГРЛС от скрапинга).\n"
                "- **Соответствие 152-ФЗ «О персональных данных»:** Обязательная фиксация согласия (`terms_accepted`), опциональная облачная синхронизация (`sync_enabled`), "
                "право на отзыв согласия и полное удаление данных (`Right to be Forgotten` / Soft-Delete аккаунта).\n"
                "- **Клиническая безопасность (Clinical Decision Support):** Предотвращение токсической передозировки при дублировании МНН, "
                "детекция непереносимости вспомогательных веществ/аллергенов и коморбидных противопоказаний."
            ),
            "contact": {
                "name": "Маркова И. Б. (ФМиИТ, МГУ им. Н.П. Огарёва)",
                "email": "markova@family.test"
            },
            "license": {
                "name": "MIT License",
                "url": "https://opensource.org/licenses/MIT"
            }
        },
        "servers": [
            {
                "url": "http://e340043kek.temp.swtest.ru/api",
                "description": "Удаленный рабочий сервер (SWTest Remote Production)"
            },
            {
                "url": "http://127.0.0.1:8000/api",
                "description": "Локальный сервер разработки (Local Development)"
            }
        ],
        "tags": [
            {"name": "Auth", "description": "Аутентификация, регистрация (152-ФЗ), профиль и управление жизненным циклом аккаунта"},
            {"name": "Dashboard", "description": "Сводная аналитика: приёмы на сегодня, критические сроки годности, активные курсы"},
            {"name": "Profiles", "description": "Управление профилями членов семьи, аллергиями и хроническими диагнозами"},
            {"name": "MedicineBoxes", "description": "Управление семейными аптечками (Домашняя, Автомобильная, Детская, Дачная)"},
            {"name": "MedicineBoxMembers", "description": "Управление совместным семейным доступом к аптечке (Owner, Editor, Viewer)"},
            {"name": "Medicines", "description": "Учёт физических упаковок лекарств, остатков, сроков годности (FEFO) и шифрование (AES-256)"},
            {"name": "ReferenceMedicines", "description": "Публичный справочник лекарственных препаратов (ГРЛС/МНН) с защитой от скрапинга"},
            {"name": "IntakeCourses", "description": "Назначение курсов приёма и автогенерация расписания логов на 14 дней"},
            {"name": "IntakeLogs", "description": "Журнал приёмов лекарств: отметка о приёме (списание остатка), пропуск, перенос (snooze)"},
            {"name": "MedicationSafety", "description": "Клинический аудит безопасности медикаментозного назначения (CDS)"},
            {"name": "Notifications", "description": "Системные уведомления о сроках годности и минимальных остатках"},
            {"name": "Reports", "description": "Аналитические отчёты: комплаенс, приверженность лечению (Adherence), непрерывные серии (streak)"}
        ],
        "components": {
            "securitySchemes": {
                "sanctumAuth": {
                    "type": "http",
                    "scheme": "bearer",
                    "bearerFormat": "Bearer {token}",
                    "description": "Токен аутентификации Laravel Sanctum. Передается в заголовке `Authorization: Bearer <token>`."
                }
            },
            "schemas": {
                "User": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 1},
                        "email": {"type": "string", "format": "email", "example": "anna@family.test"},
                        "timezone": {"type": "string", "example": "Europe/Moscow"},
                        "sync_enabled": {"type": "boolean", "example": True},
                        "consent_version": {"type": "string", "example": "1.0"},
                        "consent_given_at": {"type": "string", "format": "date-time", "example": "2026-10-29T12:00:00Z"}
                    }
                },
                "Profile": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 1},
                        "user_id": {"type": "integer", "example": 1},
                        "name": {"type": "string", "example": "Анна"},
                        "birth_date": {"type": "string", "format": "date", "nullable": True, "example": "1994-05-15"},
                        "avatar_url": {"type": "string", "nullable": True, "example": "👩"},
                        "notes": {"type": "string", "nullable": True, "example": "Основной профиль владельца"},
                        "allergies": {
                            "type": "array",
                            "items": {"type": "string"},
                            "example": ["пенициллины", "цитрусовые", "лактоза"]
                        },
                        "chronic_conditions": {
                            "type": "array",
                            "items": {"type": "string"},
                            "example": ["бронхиальная астма", "гастрит"]
                        },
                        "active_courses_count": {"type": "integer", "example": 2}
                    }
                },
                "MedicineBox": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 1},
                        "name": {"type": "string", "example": "Домашняя аптечка"},
                        "box_type": {
                            "type": "string",
                            "enum": ["home", "car", "child", "travel", "custom"],
                            "example": "home"
                        },
                        "description": {"type": "string", "nullable": True, "example": "Основная домашняя аптечка в коридоре"},
                        "medicines_count": {"type": "integer", "example": 5},
                        "role": {"type": "string", "enum": ["owner", "editor", "viewer"], "example": "owner"},
                        "pivot": {
                            "type": "object",
                            "properties": {
                                "user_id": {"type": "integer", "example": 1},
                                "medicine_box_id": {"type": "integer", "example": 1},
                                "role": {"type": "string", "enum": ["owner", "editor", "viewer"], "example": "owner"}
                            }
                        }
                    }
                },
                "MedicineBoxMember": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 2},
                        "email": {"type": "string", "format": "email", "example": "husband@family.test"},
                        "timezone": {"type": "string", "example": "Europe/Moscow"},
                        "pivot": {
                            "type": "object",
                            "properties": {
                                "role": {"type": "string", "enum": ["owner", "editor", "viewer"], "example": "editor"},
                                "invited_by_user_id": {"type": "integer", "example": 1},
                                "created_at": {"type": "string", "format": "date-time", "example": "2026-10-27T10:00:00Z"}
                            }
                        }
                    }
                },
                "ReferenceMedicine": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 1},
                        "trade_name": {"type": "string", "example": "Нурофен® Форте"},
                        "inn": {"type": "string", "example": "Ибупрофен"},
                        "dosage_form": {"type": "string", "example": "таблетки, покрытые оболочкой"},
                        "dosage": {"type": "string", "example": "400 мг"},
                        "manufacturer": {"type": "string", "example": "Рекитт Бенкизер Хелскэр"},
                        "country": {"type": "string", "example": "Великобритания"},
                        "reg_number": {"type": "string", "example": "ЛП-002345"},
                        "barcode": {"type": "string", "example": "5000158066228"},
                        "atc_code": {"type": "string", "example": "M01AE01"},
                        "composition": {"type": "string", "example": "Ибупрофен 400 мг, натрия кроскармеллоза, лактозы моногидрат..."},
                        "allergen_tags": {
                            "type": "array",
                            "items": {"type": "string"},
                            "example": ["lactose", "nsaid"]
                        },
                        "instructions": {
                            "type": "object",
                            "properties": {
                                "indications": {"type": "string", "example": "Головная и зубная боль, лихорадочные состояния"},
                                "contraindications": {"type": "string", "example": "Эрозивно-язвенные заболевания ЖКТ, астма"},
                                "dosage_rules": {"type": "string", "example": "Взрослым по 1 таблетке до 3 раз в сутки после еды"}
                            }
                        },
                        "leaflet_pdf_url": {"type": "string", "nullable": True, "example": "https://grls.rosminzdrav.ru/leaflet/12345.pdf"},
                        "storage_condition": {"type": "string", "enum": ["room", "fridge", "dark_place"], "example": "room"},
                        "days_after_opening": {"type": "integer", "nullable": True, "example": 180},
                        "status": {"type": "string", "example": "active"}
                    }
                },
                "Medicine": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 5},
                        "medicine_box_id": {"type": "integer", "example": 1},
                        "reference_medicine_id": {"type": "integer", "nullable": True, "example": 1},
                        "custom_trade_name": {"type": "string", "nullable": True, "example": "Нурофен Форте", "description": "Шифруется AES-256 в базе данных (Data-at-Rest)"},
                        "barcode": {"type": "string", "nullable": True, "example": "5000158066228"},
                        "quantity": {"type": "number", "format": "float", "example": 12.0},
                        "quantity_unit": {
                            "type": "string",
                            "enum": ["pcs", "ml", "mg", "drops", "ampoules", "sachets"],
                            "example": "pcs"
                        },
                        "expiration_date": {"type": "string", "format": "date", "example": "2027-12-31"},
                        "opened_at": {"type": "string", "format": "date", "nullable": True, "example": "2026-10-01"},
                        "days_after_opening": {"type": "integer", "nullable": True, "example": 180},
                        "effective_expiration_date": {"type": "string", "format": "date", "nullable": True, "example": "2026-11-28", "description": "Вычисляемый эффективный срок годности (модель FEFO-Opened)"},
                        "low_stock_threshold": {"type": "number", "format": "float", "example": 2.0},
                        "storage_condition": {"type": "string", "enum": ["room", "fridge", "dark_place"], "example": "room"},
                        "status": {
                            "type": "string",
                            "enum": ["active", "expiring", "expired", "finished", "disposed"],
                            "example": "active"
                        },
                        "notes": {"type": "string", "nullable": True, "example": "Принимать строго после еды", "description": "Шифруется AES-256 в базе данных"},
                        "reference_medicine": {"$ref": "#/components/schemas/ReferenceMedicine"}
                    }
                },
                "IntakeSchedule": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 10},
                        "intake_course_id": {"type": "integer", "example": 3},
                        "time_slot": {"type": "string", "example": "08:00"},
                        "dosage_quantity": {"type": "number", "format": "float", "example": 1.0}
                    }
                },
                "IntakeCourse": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 3},
                        "profile_id": {"type": "integer", "example": 1},
                        "medicine_id": {"type": "integer", "example": 5},
                        "title": {"type": "string", "example": "Курс: Нурофен Форте"},
                        "start_date": {"type": "string", "format": "date", "example": "2026-10-29"},
                        "end_date": {"type": "string", "format": "date", "nullable": True, "example": "2026-11-12"},
                        "dosage_quantity": {"type": "number", "format": "float", "example": 1.0},
                        "dosage_instruction": {"type": "string", "example": "после еды"},
                        "notes": {"type": "string", "nullable": True, "example": "Запивать стаканом воды"},
                        "is_active": {"type": "boolean", "example": True},
                        "profile": {"$ref": "#/components/schemas/Profile"},
                        "medicine": {"$ref": "#/components/schemas/Medicine"},
                        "intake_schedules": {
                            "type": "array",
                            "items": {"$ref": "#/components/schemas/IntakeSchedule"}
                        }
                    }
                },
                "IntakeLog": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 42},
                        "intake_course_id": {"type": "integer", "example": 3},
                        "medicine_id": {"type": "integer", "example": 5},
                        "scheduled_time": {"type": "string", "format": "date-time", "example": "2026-10-29T08:00:00Z"},
                        "taken_time": {"type": "string", "format": "date-time", "nullable": True, "example": "2026-10-29T08:05:00Z"},
                        "status": {
                            "type": "string",
                            "enum": ["scheduled", "taken", "skipped"],
                            "example": "taken"
                        },
                        "quantity_taken": {"type": "number", "format": "float", "example": 1.0},
                        "skipped_reason": {"type": "string", "nullable": True, "example": "Повышенное давление"},
                        "intake_course": {"$ref": "#/components/schemas/IntakeCourse"},
                        "medicine": {"$ref": "#/components/schemas/Medicine"}
                    }
                },
                "NotificationLog": {
                    "type": "object",
                    "properties": {
                        "id": {"type": "integer", "example": 1},
                        "user_id": {"type": "integer", "example": 1},
                        "type": {
                            "type": "string",
                            "enum": ["expiry_30_days", "expiry_today", "expired", "opened_expired", "low_stock"],
                            "example": "opened_expired"
                        },
                        "entity_id": {"type": "integer", "example": 5},
                        "message": {"type": "string", "example": "Срок годности препарата Нурофен истек после вскрытия."},
                        "sent_at": {"type": "string", "format": "date-time", "example": "2026-10-29T08:00:00Z"},
                        "channel": {"type": "string", "example": "in_app"}
                    }
                },
                "SafetyReport": {
                    "type": "object",
                    "properties": {
                        "safe": {"type": "boolean", "example": False},
                        "warnings": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "properties": {
                                    "severity": {"type": "string", "enum": ["critical", "warning", "info"], "example": "critical"},
                                    "type": {"type": "string", "example": "duplicate_inn"},
                                    "message": {"type": "string", "example": "Внимание! Обнаружено дублирование действующего вещества: Ибупрофен."}
                                }
                            }
                        },
                        "checked_at": {"type": "string", "format": "date-time", "example": "2026-10-29T12:00:00Z"},
                        "profile": {"type": "object"},
                        "target": {"type": "object"}
                    }
                },
                "ErrorResponse": {
                    "type": "object",
                    "properties": {
                        "message": {"type": "string", "example": "Ошибка выполнения запроса."}
                    }
                },
                "ValidationErrorResponse": {
                    "type": "object",
                    "properties": {
                        "message": {"type": "string", "example": "Переданные данные не прошли валидацию."},
                        "errors": {
                            "type": "object",
                            "additionalProperties": {
                                "type": "array",
                                "items": {"type": "string"}
                            },
                            "example": {
                                "email": ["Некорректный формат адреса электронной почты."],
                                "terms_accepted": ["Необходимо подтвердить согласие на обработку персональных данных (152-ФЗ)."]
                            }
                        }
                    }
                }
            }
        },
        "paths": {}
    }

    p = spec["paths"]

    # =========================================================================
    # 1. AUTH
    # =========================================================================
    p["/auth/register"] = {
        "post": {
            "tags": ["Auth"],
            "summary": "Регистрация нового пользователя (152-ФЗ)",
            "description": "Создает пользователя с хешированием пароля через Argon2id, фиксирует согласие на обработку ПДн (152-ФЗ), автоматически создает семейный профиль и базовую «Домашнюю аптечку», выдает Bearer-токен Sanctum. Защищено Rate Limiter (5 попыток в минуту).",
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["name", "email", "password", "password_confirmation", "terms_accepted"],
                            "properties": {
                                "name": {"type": "string", "maxLength": 255, "example": "Анна Маркова"},
                                "email": {"type": "string", "format": "email", "example": "anna@family.test"},
                                "password": {"type": "string", "format": "password", "minLength": 8, "example": "password"},
                                "password_confirmation": {"type": "string", "format": "password", "minLength": 8, "example": "password"},
                                "timezone": {"type": "string", "maxLength": 64, "example": "Europe/Moscow"},
                                "terms_accepted": {"type": "boolean", "example": True, "description": "Обязательное согласие на обработку персональных данных (152-ФЗ)"},
                                "sync_enabled": {"type": "boolean", "example": True, "description": "Согласие на облачную синхронизацию и семейный шеринг"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {
                    "description": "Успешная регистрация",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Регистрация успешно завершена."},
                                    "token": {"type": "string", "example": "1|qWeRtYuIoP123456789..."},
                                    "token_type": {"type": "string", "example": "Bearer"},
                                    "user": {"$ref": "#/components/schemas/User"},
                                    "profile": {"$ref": "#/components/schemas/Profile"},
                                    "default_box": {"$ref": "#/components/schemas/MedicineBox"}
                                }
                            }
                        }
                    }
                },
                "422": {"description": "Ошибка валидации данных", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}},
                "429": {"description": "Превышен лимит запросов (Rate Limiter)", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/auth/login"] = {
        "post": {
            "tags": ["Auth"],
            "summary": "Аутентификация пользователя (Argon2id)",
            "description": "Проверяет учетные данные с использованием алгоритма Argon2id постоянного времени, защищает от брутфорса (throttle:login — не более 5 попыток в минуту) и возвращает токен Laravel Sanctum.",
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["email", "password"],
                            "properties": {
                                "email": {"type": "string", "format": "email", "example": "anna@family.test"},
                                "password": {"type": "string", "format": "password", "example": "password"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {
                    "description": "Успешная аутентификация",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Успешная аутентификация"},
                                    "token": {"type": "string", "example": "2|abcdef1234567890..."},
                                    "token_type": {"type": "string", "example": "Bearer"},
                                    "user": {"$ref": "#/components/schemas/User"}
                                }
                            }
                        }
                    }
                },
                "401": {"description": "Неверный логин или пароль", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}},
                "429": {"description": "Превышен лимит попыток входа", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/auth/me"] = {
        "get": {
            "tags": ["Auth"],
            "summary": "Информация о текущем пользователе",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {"description": "Профиль авторизованного пользователя", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/User"}}}},
                "401": {"description": "Не авторизован", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/auth/logout"] = {
        "post": {
            "tags": ["Auth"],
            "summary": "Выход из системы (отзыв текущего токена)",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {"description": "Успешный отзыв токена", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "401": {"description": "Не авторизован", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/auth/password"] = {
        "put": {
            "tags": ["Auth"],
            "summary": "Смена пароля учетной записи",
            "description": "Требует ввода текущего пароля для верификации (Argon2id), проверяет подтверждение нового пароля и обновляет хеш.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["current_password", "password", "password_confirmation"],
                            "properties": {
                                "current_password": {"type": "string", "example": "password"},
                                "password": {"type": "string", "minLength": 8, "example": "new_secure_password"},
                                "password_confirmation": {"type": "string", "minLength": 8, "example": "new_secure_password"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Пароль успешно изменен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Неверный текущий пароль или ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/auth/account"] = {
        "delete": {
            "tags": ["Auth"],
            "summary": "Удаление учетной записи (152-ФЗ / Right to be Forgotten)",
            "description": "Требует подтверждения паролем. Выполняет отзыв всех активных токенов Sanctum, каскадный Soft-Delete семейных профилей, аптечек и учетной записи пользователя.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["current_password"],
                            "properties": {
                                "current_password": {"type": "string", "example": "password"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Аккаунт и ПДн успешно удалены", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Неверный пароль", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 2. DASHBOARD
    # =========================================================================
    p["/dashboard"] = {
        "get": {
            "tags": ["Dashboard"],
            "summary": "Сводный дашборд ключевых показателей",
            "description": "Возвращает агрегированные метрики для главного экрана мобильного приложения: общее число лекарств, истекающие (до 30 дней), просроченные, статистика приёмов на сегодня (всего, принято, осталось), количество активных курсов.",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {
                    "description": "Метрики дашборда",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "data": {
                                        "type": "object",
                                        "properties": {
                                            "total_medicines": {"type": "integer", "example": 24},
                                            "expiring_medicines": {"type": "integer", "example": 2},
                                            "expired_medicines": {"type": "integer", "example": 1},
                                            "today_intakes": {
                                                "type": "object",
                                                "properties": {
                                                    "total": {"type": "integer", "example": 5},
                                                    "taken": {"type": "integer", "example": 3},
                                                    "remaining": {"type": "integer", "example": 2}
                                                }
                                            },
                                            "active_courses_count": {"type": "integer", "example": 3}
                                        }
                                    }
                                }
                            }
                        }
                    }
                },
                "401": {"description": "Не авторизован", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 3. PROFILES
    # =========================================================================
    p["/profiles"] = {
        "get": {
            "tags": ["Profiles"],
            "summary": "Список профилей членов семьи",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {
                    "description": "Коллекция профилей с количеством активных курсов",
                    "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"type": "array", "items": {"$ref": "#/components/schemas/Profile"}}}}}}
                }
            }
        },
        "post": {
            "tags": ["Profiles"],
            "summary": "Создание нового профиля члена семьи",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["name"],
                            "properties": {
                                "name": {"type": "string", "maxLength": 255, "example": "Бабушка Елена"},
                                "birth_date": {"type": "string", "format": "date", "example": "1954-08-12"},
                                "avatar_url": {"type": "string", "example": "👵"},
                                "notes": {"type": "string", "example": "Хроническая гипертония"},
                                "allergies": {"type": "array", "items": {"type": "string"}, "example": ["пенициллины", "сульфаниламиды"]},
                                "chronic_conditions": {"type": "array", "items": {"type": "string"}, "example": ["артериальная гипертензия"]}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {"description": "Профиль успешно создан", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/Profile"}}}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/profiles/{id}"] = {
        "get": {
            "tags": ["Profiles"],
            "summary": "Просмотр профиля члена семьи",
            "description": "Защита от IDOR: доступ разрешен только к профилям, принадлежащим учетной записи авторизованного пользователя.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Данные профиля", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/Profile"}}}}}},
                "403": {"description": "Доступ запрещен (IDOR protection)", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "404": {"description": "Профиль не найден", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "put": {
            "tags": ["Profiles"],
            "summary": "Обновление профиля члена семьи",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "name": {"type": "string", "example": "Бабушка Елена"},
                                "birth_date": {"type": "string", "format": "date", "example": "1954-08-12"},
                                "avatar_url": {"type": "string", "example": "👵"},
                                "notes": {"type": "string", "example": "Контроль АД дважды в день"},
                                "allergies": {"type": "array", "items": {"type": "string"}, "example": ["пенициллины"]},
                                "chronic_conditions": {"type": "array", "items": {"type": "string"}, "example": ["артериальная гипертензия"]}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Профиль успешно обновлен", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/Profile"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        },
        "delete": {
            "tags": ["Profiles"],
            "summary": "Удаление профиля члена семьи (Soft-delete)",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Профиль успешно удален", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 4. MEDICINE BOXES
    # =========================================================================
    p["/medicine-boxes"] = {
        "get": {
            "tags": ["MedicineBoxes"],
            "summary": "Список аптечек текущего пользователя",
            "description": "Возвращает все аптечки, к которым у пользователя есть доступ (как владельца или участника с ролью editor/viewer).",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {"description": "Список аптечек", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"type": "array", "items": {"$ref": "#/components/schemas/MedicineBox"}}}}}}}
            }
        },
        "post": {
            "tags": ["MedicineBoxes"],
            "summary": "Создание новой аптечки",
            "description": "Создатель автоматически получает роль `owner` в таблице связей.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["name", "box_type"],
                            "properties": {
                                "name": {"type": "string", "example": "Автомобильная аптечка"},
                                "box_type": {"type": "string", "enum": ["home", "car", "child", "travel", "custom"], "example": "car"},
                                "description": {"type": "string", "example": "Аптечка первой помощи в багажнике"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {"description": "Аптечка создана", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/MedicineBox"}}}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/medicine-boxes/{id}"] = {
        "get": {
            "tags": ["MedicineBoxes"],
            "summary": "Просмотр содержимого аптечки",
            "description": "Защита от IDOR через `MedicineBoxPolicy::view`.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Данные аптечки со списком лекарств", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/MedicineBox"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "put": {
            "tags": ["MedicineBoxes"],
            "summary": "Обновление параметров аптечки",
            "description": "Доступно владельцу (`owner`) и редактору (`editor`).",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "name": {"type": "string", "example": "Домашняя аптечка (Кухня)"},
                                "box_type": {"type": "string", "enum": ["home", "car", "child", "travel", "custom"], "example": "home"},
                                "description": {"type": "string", "example": "Верхний шкаф кухни"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Аптечка обновлена", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/MedicineBox"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "delete": {
            "tags": ["MedicineBoxes"],
            "summary": "Удаление аптечки (Soft-delete)",
            "description": "Доступно исключительно владельцу аптечки (`owner`). Защита от потери данных: запрещено удалять аптечку, в которой содержатся препараты (возврат 422 Unprocessable Entity).",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Аптечка удалена", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Доступ запрещен (только owner)", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Нельзя удалить непустую аптечку", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 5. MEDICINE BOX MEMBERS
    # =========================================================================
    p["/medicine-boxes/{id}/members"] = {
        "get": {
            "tags": ["MedicineBoxMembers"],
            "summary": "Список участников аптечки с ролями",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Список участников с ролями", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"type": "array", "items": {"$ref": "#/components/schemas/MedicineBoxMember"}}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "post": {
            "tags": ["MedicineBoxMembers"],
            "summary": "Приглашение участника в семейную аптечку",
            "description": "Требует согласия 152-ФЗ на синхронизацию (`sync_enabled = true`) у владельца и приглашаемого. Доступно только владельцу (`owner`). Роли: `editor`, `viewer`.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["email", "role"],
                            "properties": {
                                "email": {"type": "string", "format": "email", "example": "husband@family.test"},
                                "role": {"type": "string", "enum": ["editor", "viewer"], "example": "editor"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {"description": "Участник успешно добавлен", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"type": "object"}}}}}},
                "200": {"description": "Роль участника успешно обновлена", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"type": "object"}}}}}},
                "403": {"description": "Запрещено политикой или отключена синхронизация 152-ФЗ", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации или пользователь является владельцем", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/medicine-boxes/{id}/members/{user_id}"] = {
        "delete": {
            "tags": ["MedicineBoxMembers"],
            "summary": "Исключение участника из аптечки",
            "description": "Доступно только владельцу. Запрещено удалять себя как единственного владельца (HTTP 422).",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1},
                {"name": "user_id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 2}
            ],
            "responses": {
                "200": {"description": "Участник исключен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Только владелец может исключать участников", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Нельзя удалить единственного владельца", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 6. MEDICINES
    # =========================================================================
    p["/medicines"] = {
        "get": {
            "tags": ["Medicines"],
            "summary": "Список препаратов в аптечках пользователя с пагинацией",
            "description": "Защита от IDOR. Поддерживает поиск по справочнику ГРЛС и расшифрованному наименованию (AES-256), фильтрацию по аптечке, статусу и истекающим срокам (в пределах 30 дней).",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "medicine_box_id", "in": "query", "required": False, "schema": {"type": "integer"}, "description": "Фильтр по конкретной аптечке"},
                {"name": "status", "in": "query", "required": False, "schema": {"type": "string", "enum": ["active", "expiring", "expired", "finished", "disposed"]}},
                {"name": "expiring", "in": "query", "required": False, "schema": {"type": "boolean"}, "description": "Только истекающие в ближайшие 30 дней"},
                {"name": "q", "in": "query", "required": False, "schema": {"type": "string"}, "description": "Поисковая строка (поиск по ГРЛС и зашифрованному custom_trade_name)"},
                {"name": "page", "in": "query", "required": False, "schema": {"type": "integer", "default": 1}},
                {"name": "per_page", "in": "query", "required": False, "schema": {"type": "integer", "default": 25, "maximum": 100}}
            ],
            "responses": {
                "200": {
                    "description": "Пагинированный список препаратов",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "current_page": {"type": "integer", "example": 1},
                                    "data": {"type": "array", "items": {"$ref": "#/components/schemas/Medicine"}},
                                    "per_page": {"type": "integer", "example": 25},
                                    "total": {"type": "integer", "example": 10}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Доступ к указанной аптечке запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "post": {
            "tags": ["Medicines"],
            "summary": "Добавление лекарственного препарата в аптечку",
            "description": "Поля `custom_trade_name` и `notes` шифруются алгоритмом AES-256 (Data-at-Rest). Проверяется право редактирования аптечки.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["medicine_box_id", "quantity", "quantity_unit", "expiration_date"],
                            "properties": {
                                "medicine_box_id": {"type": "integer", "example": 1},
                                "reference_medicine_id": {"type": "integer", "nullable": True, "example": 1},
                                "custom_trade_name": {"type": "string", "example": "Парацетамол Экстра", "description": "Шифруется AES-256"},
                                "barcode": {"type": "string", "example": "4607008360012"},
                                "quantity": {"type": "number", "format": "float", "minimum": 0.001, "example": 20.0},
                                "quantity_unit": {"type": "string", "enum": ["pcs", "ml", "mg", "drops", "ampoules", "sachets"], "example": "pcs"},
                                "expiration_date": {"type": "string", "format": "date", "example": "2027-08-30"},
                                "opened_at": {"type": "string", "format": "date", "nullable": True, "example": "2026-10-20"},
                                "days_after_opening": {"type": "integer", "minimum": 1, "example": 90},
                                "low_stock_threshold": {"type": "number", "format": "float", "example": 3.0},
                                "storage_condition": {"type": "string", "enum": ["room", "fridge", "dark_place"], "example": "room"},
                                "notes": {"type": "string", "example": "Принимать строго при температуре выше 38.5C", "description": "Шифруется AES-256"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {"description": "Препарат успешно добавлен", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/Medicine"}}}}}},
                "403": {"description": "IDOR: Нет прав на изменение указанной аптечки", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации входных данных", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/medicines/{id}"] = {
        "get": {
            "tags": ["Medicines"],
            "summary": "Просмотр детальной карточки препарата",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 5}
            ],
            "responses": {
                "200": {"description": "Детальная карточка препарата", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/Medicine"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "404": {"description": "Препарат не найден", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "put": {
            "tags": ["Medicines"],
            "summary": "Обновление параметров препарата (остаток, дата вскрытия, статус)",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 5}
            ],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "custom_trade_name": {"type": "string", "example": "Нурофен Форте"},
                                "quantity": {"type": "number", "format": "float", "example": 15.0},
                                "expiration_date": {"type": "string", "format": "date", "example": "2027-12-31"},
                                "opened_at": {"type": "string", "format": "date", "example": "2026-10-25"},
                                "days_after_opening": {"type": "integer", "example": 180},
                                "low_stock_threshold": {"type": "number", "format": "float", "example": 2.0},
                                "storage_condition": {"type": "string", "enum": ["room", "fridge", "dark_place"], "example": "room"},
                                "status": {"type": "string", "enum": ["active", "expiring", "expired", "finished", "disposed"], "example": "active"},
                                "notes": {"type": "string", "example": "Хранить в сухом месте"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Препарат обновлен", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/Medicine"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        },
        "delete": {
            "tags": ["Medicines"],
            "summary": "Удаление препарата из аптечки",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 5}
            ],
            "responses": {
                "200": {"description": "Препарат успешно удален", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/medicines/{id}/open"] = {
        "post": {
            "tags": ["Medicines"],
            "summary": "Фиксация факта вскрытия флакона / упаковки препарата",
            "description": "Фиксирует дату вскрытия (opened_at = now()), устанавливает срок хранения после вскрытия (days_after_opening) и рассчитывает эффективный срок годности (effective_expiration_date). При истечении статусу присваивается expired.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 5}
            ],
            "requestBody": {
                "required": False,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "days_after_opening": {"type": "integer", "minimum": 1, "maximum": 3650, "example": 30, "description": "Срок годности после вскрытия в днях"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {
                    "description": "Флакон успешно вскрыт",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Флакон успешно вскрыт."},
                                    "data": {"$ref": "#/components/schemas/Medicine"}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "404": {"description": "Препарат не найден", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 7. REFERENCE MEDICINES
    # =========================================================================
    p["/reference-medicines"] = {
        "get": {
            "tags": ["ReferenceMedicines"],
            "summary": "Поиск по каталогу ГРЛС / МНН (Публичный)",
            "description": "Поиск по официальному справочнику лекарств (торговое наименование, МНН, штрихкод, АТХ). Защищен от парсинга Rate Limiter (60 запросов в минуту).",
            "parameters": [
                {"name": "q", "in": "query", "required": False, "schema": {"type": "string"}, "example": "ибупрофен", "description": "Поисковый запрос"},
                {"name": "search", "in": "query", "required": False, "schema": {"type": "string"}, "example": "ибупрофен"}
            ],
            "responses": {
                "200": {
                    "description": "Результаты поиска по справочнику",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "query": {"type": "string", "example": "ибупрофен"},
                                    "count": {"type": "integer", "example": 3},
                                    "data": {"type": "array", "items": {"$ref": "#/components/schemas/ReferenceMedicine"}}
                                }
                            }
                        }
                    }
                },
                "429": {"description": "Превышен лимит запросов (Rate Limiter: 60/min)", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/reference-medicines/{id}"] = {
        "get": {
            "tags": ["ReferenceMedicines"],
            "summary": "Карточка препарата из каталога ГРЛС",
            "description": "Возвращает подробный состав, аллергенные теги, инструкции и ссылку на официальный вкладыш PDF.",
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Детальные данные справочника", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/ReferenceMedicine"}}}}}},
                "404": {"description": "Препарат не найден в каталоге", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 8. INTAKE COURSES
    # =========================================================================
    p["/intake-courses"] = {
        "get": {
            "tags": ["IntakeCourses"],
            "summary": "Список курсов приёма лекарств с пагинацией",
            "description": "Защита от IDOR. Возвращает курсы членов семьи текущего пользователя, с возможностью фильтрации по profile_id.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "profile_id", "in": "query", "required": False, "schema": {"type": "integer"}, "description": "Фильтр по члену семьи"},
                {"name": "active_only", "in": "query", "required": False, "schema": {"type": "boolean"}, "description": "Только активные курсы"},
                {"name": "page", "in": "query", "required": False, "schema": {"type": "integer", "default": 1}}
            ],
            "responses": {
                "200": {
                    "description": "Пагинированный список курсов",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "current_page": {"type": "integer", "example": 1},
                                    "data": {"type": "array", "items": {"$ref": "#/components/schemas/IntakeCourse"}},
                                    "total": {"type": "integer", "example": 3}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Доступ к профилю запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "post": {
            "tags": ["IntakeCourses"],
            "summary": "Назначение курса приёма и автогенерация логов на 14 дней",
            "description": "В атомарной транзакции создается курс (`intake_courses`), слоты расписания (`intake_schedules`) и логи приёмов на 14 дней вперед. Проводится аудит клинической безопасности (CDS) с возвратом предупреждений.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["profile_id", "medicine_id", "start_date", "dosage_quantity"],
                            "properties": {
                                "profile_id": {"type": "integer", "example": 1},
                                "medicine_id": {"type": "integer", "example": 5},
                                "title": {"type": "string", "example": "Курс терапии при ОРВИ"},
                                "start_date": {"type": "string", "format": "date", "example": "2026-10-29"},
                                "end_date": {"type": "string", "format": "date", "nullable": True, "example": "2026-11-05"},
                                "dosage_quantity": {"type": "number", "format": "float", "example": 1.0},
                                "rule": {"type": "string", "example": "после еды"},
                                "dosage_instruction": {"type": "string", "example": "после еды, запивая водой"},
                                "time_slots": {
                                    "type": "array",
                                    "items": {"type": "string", "pattern": "^\\d{1,2}:\\d{2}$"},
                                    "example": ["08:00", "14:00", "20:00"]
                                },
                                "notes": {"type": "string", "example": "Строго не натощак"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {
                    "description": "Курс назначен, расписание сгенерировано",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Курс приёма успешно назначен."},
                                    "data": {"$ref": "#/components/schemas/IntakeCourse"},
                                    "logs_generated_count": {"type": "integer", "example": 24},
                                    "safety": {"$ref": "#/components/schemas/SafetyReport"},
                                    "warnings": {"type": "array", "items": {"type": "object"}}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Профиль или лекарство не принадлежат пользователю", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации параметров", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/intake-courses/{id}"] = {
        "get": {
            "tags": ["IntakeCourses"],
            "summary": "Просмотр деталей курса лечения",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 3}
            ],
            "responses": {
                "200": {"description": "Детали курса со слотами расписания и логами", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/IntakeCourse"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        },
        "delete": {
            "tags": ["IntakeCourses"],
            "summary": "Удаление / завершение курса приёма",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 3}
            ],
            "responses": {
                "200": {"description": "Курс приёма успешно удален", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 9. INTAKE LOGS
    # =========================================================================
    p["/intake-logs"] = {
        "get": {
            "tags": ["IntakeLogs"],
            "summary": "Журнал приёмов лекарств (на день или история)",
            "description": "По умолчанию возвращает список приёмов на сегодня (`date=today`). При запросе истории (`?history=1` или `?date=history`) переключается в пагинированный режим по 50 записей.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "date", "in": "query", "required": False, "schema": {"type": "string"}, "example": "today", "description": "'today', 'YYYY-MM-DD', 'history' или 'all'"},
                {"name": "profile_id", "in": "query", "required": False, "schema": {"type": "integer"}, "example": 1},
                {"name": "status", "in": "query", "required": False, "schema": {"type": "string", "enum": ["scheduled", "taken", "skipped"]}},
                {"name": "history", "in": "query", "required": False, "schema": {"type": "boolean"}, "description": "Режим полной истории с пагинацией"},
                {"name": "page", "in": "query", "required": False, "schema": {"type": "integer", "default": 1}}
            ],
            "responses": {
                "200": {
                    "description": "Список логов на дату либо пагинация истории",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "date": {"type": "string", "example": "2026-10-29"},
                                    "data": {"type": "array", "items": {"$ref": "#/components/schemas/IntakeLog"}}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/intake-logs/quick"] = {
        "post": {
            "tags": ["IntakeLogs"],
            "summary": "Разовый приём по требованию (PRN / Quick Intake)",
            "description": "Фиксирует разовый симптоматический приём лекарства («по требованию»), автоматически создает или находит PRN-курс, создает запись в журнале со статусом taken и атомарно списывает дозу из физического остатка препарата в аптечке.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["medicine_id", "profile_id"],
                            "properties": {
                                "medicine_id": {"type": "integer", "example": 1, "description": "Идентификатор лекарства в аптечке"},
                                "profile_id": {"type": "integer", "example": 1, "description": "Идентификатор профиля члена семьи"},
                                "quantity": {"type": "number", "format": "float", "minimum": 0.1, "maximum": 10.0, "default": 1.0, "example": 1.0, "description": "Принятая доза (по умолчанию 1.0)"},
                                "symptom": {"type": "string", "maxLength": 255, "example": "Головная боль", "description": "Симптом или причина приёма"},
                                "taken_at": {"type": "string", "format": "date-time", "example": "2026-10-29T20:30:00Z", "description": "Время приёма (по умолчанию текущее)"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "201": {
                    "description": "Разовый приём успешно зафиксирован, остаток списан",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Разовый приём успешно зафиксирован, остаток списан."},
                                    "data": {"$ref": "#/components/schemas/IntakeLog"},
                                    "remaining_quantity": {"type": "number", "format": "float", "example": 9.0}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Профиль или аптечка не принадлежат пользователю", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации входных параметров", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    p["/intake-logs/{id}"] = {
        "get": {
            "tags": ["IntakeLogs"],
            "summary": "Просмотр записи о приёме",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 42}
            ],
            "responses": {
                "200": {"description": "Детали лога приёма", "content": {"application/json": {"schema": {"type": "object", "properties": {"data": {"$ref": "#/components/schemas/IntakeLog"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/intake-logs/{id}/take"] = {
        "post": {
            "tags": ["IntakeLogs"],
            "summary": "Отметка о приёме лекарства (Take) и списание остатка",
            "description": "В атомарной транзакции статус лога переводится в `taken`, фиксируется `taken_time`, а из физического остатка препарата (`medicines.quantity`) вычитается доза. При обнулении остатка статус меняется на `finished`.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 42}
            ],
            "responses": {
                "200": {
                    "description": "Приём зафиксирован, остаток списан",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "message": {"type": "string", "example": "Приём лекарства успешно зафиксирован. Остаток списан."},
                                    "data": {"$ref": "#/components/schemas/IntakeLog"},
                                    "remaining_quantity": {"type": "number", "format": "float", "example": 19.0}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Приём не относится к профилям пользователя", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/intake-logs/{id}/skip"] = {
        "post": {
            "tags": ["IntakeLogs"],
            "summary": "Пропуск приёма лекарства с фиксацией причины",
            "description": "Статус переводится в `skipped`, сохраняется указанная причина пропуска (например, плохое самочувствие). Остаток препарата в аптечке не списывается.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 42}
            ],
            "requestBody": {
                "required": False,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "reason": {"type": "string", "maxLength": 255, "example": "Тошнота после еды"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Приём отмечен как пропущенный", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string"}, "data": {"$ref": "#/components/schemas/IntakeLog"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    p["/intake-logs/{id}/snooze"] = {
        "post": {
            "tags": ["IntakeLogs"],
            "summary": "Откладывание приёма лекарства на +N минут (Snooze)",
            "description": "Сдвигает `scheduled_time` на указанное количество минут (по умолчанию +15 мин, от 5 до 120 мин) для напоминания в мобильном приложении.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 42}
            ],
            "requestBody": {
                "required": False,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "properties": {
                                "minutes": {"type": "integer", "minimum": 5, "maximum": 120, "default": 15, "example": 15}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {"description": "Время приёма сдвинуто", "content": {"application/json": {"schema": {"type": "object", "properties": {"message": {"type": "string", "example": "Приём лекарства успешно отложен на 15 мин."}, "data": {"$ref": "#/components/schemas/IntakeLog"}}}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 10. MEDICATION SAFETY (CDS)
    # =========================================================================
    p["/medication-safety/check"] = {
        "post": {
            "tags": ["MedicationSafety"],
            "summary": "Превентивный клинический анализ безопасности (CDS)",
            "description": "Проверяет совместимость лекарства с профилем пациента перед назначением: дублирование МНН и АТХ-групп, критические аллергии на активные вещества (пенициллины, НПВП), аллергии на вспомогательные эксципиенты (лактоза, цитрусовые ароматизаторы) и коморбидные патологии (астма, гастрит, гипертензия). Защищено от IDOR.",
            "security": [{"sanctumAuth": []}],
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["profile_id"],
                            "properties": {
                                "profile_id": {"type": "integer", "example": 1, "description": "Идентификатор профиля члена семьи"},
                                "medicine_id": {"type": "integer", "example": 5, "description": "Идентификатор физической упаковки в аптечке"},
                                "reference_medicine_id": {"type": "integer", "example": 1, "description": "Либо идентификатор препарата из каталога ГРЛС"}
                            }
                        }
                    }
                }
            },
            "responses": {
                "200": {
                    "description": "Клинический отчет о рисках и противопоказаниях",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "data": {"$ref": "#/components/schemas/SafetyReport"}
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Профиль или аптечка не принадлежат пользователю", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "422": {"description": "Ошибка валидации параметров", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ValidationErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 11. NOTIFICATIONS
    # =========================================================================
    p["/notifications"] = {
        "get": {
            "tags": ["Notifications"],
            "summary": "Список системных уведомлений пользователя",
            "description": "Возвращает список уведомлений о приближении срока годности, истечении срока после вскрытия (opened_expired) и критическом снижении запасов (low_stock).",
            "security": [{"sanctumAuth": []}],
            "responses": {
                "200": {
                    "description": "Список уведомлений",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "data": {"type": "array", "items": {"$ref": "#/components/schemas/NotificationLog"}}
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    p["/notifications/{id}"] = {
        "delete": {
            "tags": ["Notifications"],
            "summary": "Удаление системного уведомления",
            "description": "Защита от IDOR: пользователь может удалять только свои уведомления.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "id", "in": "path", "required": True, "schema": {"type": "integer"}, "example": 1}
            ],
            "responses": {
                "200": {"description": "Уведомление успешно удалено", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}},
                "404": {"description": "Уведомление не найдено", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    # =========================================================================
    # 12. REPORTS (ANALYTICS & ADHERENCE)
    # =========================================================================
    p["/reports/adherence"] = {
        "get": {
            "tags": ["Reports"],
            "summary": "Аналитический отчёт комплаенса и приверженности лечению (Adherence Report)",
            "description": "Формирует структурированную аналитику: комплаентность (adherence_rate), текущая непрерывная серия приёмов (streak_days), посуточная статистика и разбивка по препаратам.",
            "security": [{"sanctumAuth": []}],
            "parameters": [
                {"name": "period", "in": "query", "required": False, "schema": {"type": "string", "enum": ["7_days", "30_days", "all"], "default": "7_days"}, "description": "Период анализа"},
                {"name": "profile_id", "in": "query", "required": False, "schema": {"type": "integer"}, "description": "ID профиля члена семьи (IDOR защита)"},
                {"name": "family_member_id", "in": "query", "required": False, "schema": {"type": "integer"}, "description": "Алиас для profile_id"}
            ],
            "responses": {
                "200": {
                    "description": "Аналитический отчёт комплаенса",
                    "content": {
                        "application/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "period": {
                                        "type": "object",
                                        "properties": {
                                            "type": {"type": "string", "example": "7_days"},
                                            "start_date": {"type": "string", "format": "date", "example": "2026-10-26"},
                                            "end_date": {"type": "string", "format": "date", "example": "2026-11-01"}
                                        }
                                    },
                                    "scheduled_count": {"type": "integer", "example": 14},
                                    "taken_count": {"type": "integer", "example": 12},
                                    "missed_count": {"type": "integer", "example": 2},
                                    "adherence_rate": {"type": "number", "format": "float", "example": 85.7},
                                    "streak_days": {"type": "integer", "example": 5},
                                    "daily_stats": {
                                        "type": "array",
                                        "items": {
                                            "type": "object",
                                            "properties": {
                                                "date": {"type": "string", "format": "date", "example": "2026-10-26"},
                                                "scheduled": {"type": "integer", "example": 2},
                                                "taken": {"type": "integer", "example": 2},
                                                "missed": {"type": "integer", "example": 0}
                                            }
                                        }
                                    },
                                    "medicines_summary": {
                                        "type": "array",
                                        "items": {
                                            "type": "object",
                                            "properties": {
                                                "medicine_id": {"type": "integer", "example": 1},
                                                "name": {"type": "string", "example": "Ибупрофен 400 мг"},
                                                "scheduled_count": {"type": "integer", "example": 14},
                                                "taken_count": {"type": "integer", "example": 12},
                                                "missed_count": {"type": "integer", "example": 2},
                                                "adherence_rate": {"type": "number", "format": "float", "example": 85.7}
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                },
                "403": {"description": "IDOR: Доступ запрещен", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ErrorResponse"}}}}
            }
        }
    }

    return spec


# --------------------------------------------------------------------------
# 2. POSTMAN COLLECTION V2.1.0
# --------------------------------------------------------------------------

def build_postman_collection():
    collection = {
        "info": {
            "_postman_id": "a9e6d8b2-5f34-4b8c-9e21-d1f8a7c64210",
            "name": "Семейная Аптечка — REST API (Sanctum, Argon2id, AES-256)",
            "description": (
                "Интерактивная коллекция Postman v2.1.0 для тестирования и демонстрации REST API защищенной системы «Семейная аптечка».\n\n"
                "Сервер по умолчанию: {{baseUrl}} (удаленный: http://e340043kek.temp.swtest.ru/api или локальный: http://127.0.0.1:8000/api).\n"
                "Авторизация: Выполните запрос в папке `01. Auth / Login (Авторизация)` — токен Sanctum автоматически запишется в переменную коллекции `{{token}}`."
            ),
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
        },
        "variable": [
            {
                "key": "baseUrl",
                "value": "http://e340043kek.temp.swtest.ru/api",
                "type": "string"
            },
            {
                "key": "token",
                "value": "",
                "type": "string"
            },
            {
                "key": "profile_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "box_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "medicine_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "reference_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "course_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "log_id",
                "value": "1",
                "type": "string"
            },
            {
                "key": "notification_id",
                "value": "1",
                "type": "string"
            }
        ],
        "item": []
    }

    # Helper for creating standard test scripts
    def status_test(expected=[200, 201]):
        statuses = ", ".join(str(s) for s in expected)
        return {
            "listen": "test",
            "script": {
                "type": "text/javascript",
                "exec": [
                    f"pm.test('Status code is {statuses}', function () {{",
                    f"    pm.expect(pm.response.code).to.be.oneOf([{statuses}]);",
                    "});"
                ]
            }
        }

    # Helper for Bearer auth
    bearer_auth = {
        "type": "bearer",
        "bearer": [
            {"key": "token", "value": "{{token}}", "type": "string"}
        ]
    }

    # Folder 1: Auth
    auth_folder = {
        "name": "01. Auth (Аутентификация и 152-ФЗ)",
        "item": [
            {
                "name": "Login (Авторизация и получение токена)",
                "event": [
                    {
                        "listen": "test",
                        "script": {
                            "type": "text/javascript",
                            "exec": [
                                "pm.test('Status code is 200', function () {",
                                "    pm.response.to.have.status(200);",
                                "});",
                                "if (pm.response.code === 200) {",
                                "    var jsonData = pm.response.json();",
                                "    if (jsonData.token) {",
                                "        pm.collectionVariables.set('token', jsonData.token);",
                                "        console.log('Sanctum Token сохранен в переменную token');",
                                "    }",
                                "}"
                            ]
                        }
                    }
                ],
                "request": {
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({"email": "anna@family.test", "password": "password"}, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/auth/login",
                        "host": ["{{baseUrl}}"],
                        "path": ["auth", "login"]
                    },
                    "description": "Вход пользователя в систему с проверкой Argon2id и автосохранением токена в коллекцию."
                }
            },
            {
                "name": "Register New User (Регистрация по 152-ФЗ)",
                "event": [status_test([201])],
                "request": {
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "name": "Новый Пользователь",
                            "email": "new_user_vkr@family.test",
                            "password": "password123",
                            "password_confirmation": "password123",
                            "timezone": "Europe/Moscow",
                            "terms_accepted": True,
                            "sync_enabled": True
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/auth/register",
                        "host": ["{{baseUrl}}"],
                        "path": ["auth", "register"]
                    }
                }
            },
            {
                "name": "Current User Profile (/auth/me)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/auth/me",
                        "host": ["{{baseUrl}}"],
                        "path": ["auth", "me"]
                    }
                }
            },
            {
                "name": "Update Password (/auth/password)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "PUT",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "current_password": "password",
                            "password": "password_new",
                            "password_confirmation": "password_new"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/auth/password",
                        "host": ["{{baseUrl}}"],
                        "path": ["auth", "password"]
                    }
                }
            },
            {
                "name": "Logout (/auth/logout)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/auth/logout",
                        "host": ["{{baseUrl}}"],
                        "path": ["auth", "logout"]
                    }
                }
            }
        ]
    }
    collection["item"].append(auth_folder)

    # Folder 2: Dashboard
    dash_folder = {
        "name": "02. Dashboard (Сводная аналитика)",
        "item": [
            {
                "name": "Get Dashboard Metrics",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/dashboard",
                        "host": ["{{baseUrl}}"],
                        "path": ["dashboard"]
                    }
                }
            },
            {
                "name": "Get Adherence Report (Комплаенс)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/reports/adherence?period=7_days",
                        "host": ["{{baseUrl}}"],
                        "path": ["reports", "adherence"],
                        "query": [{"key": "period", "value": "7_days"}]
                    }
                }
            }
        ]
    }
    collection["item"].append(dash_folder)

    # Folder 3: Profiles
    profiles_folder = {
        "name": "03. Profiles (Профили членов семьи)",
        "item": [
            {
                "name": "List Family Profiles",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/profiles",
                        "host": ["{{baseUrl}}"],
                        "path": ["profiles"]
                    }
                }
            },
            {
                "name": "Create Profile (с аллергенами)",
                "event": [status_test([201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "name": "Сын Миша",
                            "birth_date": "2018-06-10",
                            "avatar_url": "🧒",
                            "notes": "Аллергия на цитрусовые",
                            "allergies": ["цитрусовые", "красители"],
                            "chronic_conditions": []
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/profiles",
                        "host": ["{{baseUrl}}"],
                        "path": ["profiles"]
                    }
                }
            },
            {
                "name": "Get Profile by ID",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/profiles/{{profile_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["profiles", "{{profile_id}}"]
                    }
                }
            },
            {
                "name": "Update Profile",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "PUT",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "name": "Сын Миша (обновлено)",
                            "notes": "Посещает секцию плавания"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/profiles/{{profile_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["profiles", "{{profile_id}}"]
                    }
                }
            }
        ]
    }
    collection["item"].append(profiles_folder)

    # Folder 4: MedicineBoxes
    boxes_folder = {
        "name": "04. Medicine Boxes (Семейные аптечки)",
        "item": [
            {
                "name": "List Medicine Boxes",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicine-boxes",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicine-boxes"]
                    }
                }
            },
            {
                "name": "Create Medicine Box",
                "event": [status_test([201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "name": "Дачная аптечка",
                            "box_type": "travel",
                            "description": "Аптечка в загородном доме"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/medicine-boxes",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicine-boxes"]
                    }
                }
            },
            {
                "name": "Get Medicine Box Content",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicine-boxes/{{box_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicine-boxes", "{{box_id}}"]
                    }
                }
            }
        ]
    }
    collection["item"].append(boxes_folder)

    # Folder 5: Box Sharing
    sharing_folder = {
        "name": "05. Box Sharing & Members (Семейный доступ)",
        "item": [
            {
                "name": "List Box Members",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicine-boxes/{{box_id}}/members",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicine-boxes", "{{box_id}}", "members"]
                    }
                }
            },
            {
                "name": "Invite Box Member (Editor)",
                "event": [status_test([200, 201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "email": "doctor@clinic.test",
                            "role": "viewer"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/medicine-boxes/{{box_id}}/members",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicine-boxes", "{{box_id}}", "members"]
                    }
                }
            }
        ]
    }
    collection["item"].append(sharing_folder)

    # Folder 6: Medicines
    medicines_folder = {
        "name": "06. Medicines (Учёт упаковок лекарств)",
        "item": [
            {
                "name": "List Medicines (Paginated)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicines?page=1&per_page=25",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicines"],
                        "query": [
                            {"key": "page", "value": "1"},
                            {"key": "per_page", "value": "25"}
                        ]
                    }
                }
            },
            {
                "name": "Search Medicines (Поиск по названию/ГРЛС)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicines?q=парацетамол",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicines"],
                        "query": [{"key": "q", "value": "парацетамол"}]
                    }
                }
            },
            {
                "name": "Create Medicine (с AES-256 шифрованием)",
                "event": [status_test([201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "medicine_box_id": 1,
                            "reference_medicine_id": 1,
                            "custom_trade_name": "Нурофен Форте",
                            "barcode": "5000158066228",
                            "quantity": 20.0,
                            "quantity_unit": "pcs",
                            "expiration_date": "2027-12-31",
                            "opened_at": "2026-10-25",
                            "days_after_opening": 180,
                            "low_stock_threshold": 3.0,
                            "storage_condition": "room",
                            "notes": "Хранить в сухом месте"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/medicines",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicines"]
                    }
                }
            },
            {
                "name": "Get Medicine by ID",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/medicines/{{medicine_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicines", "{{medicine_id}}"]
                    }
                }
            },
            {
                "name": "Open Medicine Bottle (Модель FEFO-Opened)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "days_after_opening": 30
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/medicines/{{medicine_id}}/open",
                        "host": ["{{baseUrl}}"],
                        "path": ["medicines", "{{medicine_id}}", "open"]
                    }
                }
            }
        ]
    }
    collection["item"].append(medicines_folder)

    # Folder 7: Reference Medicines
    ref_folder = {
        "name": "07. Reference Medicines (Каталог ГРЛС/МНН)",
        "item": [
            {
                "name": "Search Catalog (ГРЛС)",
                "event": [status_test([200])],
                "request": {
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/reference-medicines?q=ибупрофен",
                        "host": ["{{baseUrl}}"],
                        "path": ["reference-medicines"],
                        "query": [{"key": "q", "value": "ибупрофен"}]
                    }
                }
            },
            {
                "name": "Get Reference Medicine Details",
                "event": [status_test([200])],
                "request": {
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/reference-medicines/{{reference_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["reference-medicines", "{{reference_id}}"]
                    }
                }
            }
        ]
    }
    collection["item"].append(ref_folder)

    # Folder 8: Intake Courses
    courses_folder = {
        "name": "08. Intake Courses (Курсы приёма)",
        "item": [
            {
                "name": "List Intake Courses",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/intake-courses",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-courses"]
                    }
                }
            },
            {
                "name": "Create Intake Course (с автогенерацией логов)",
                "event": [status_test([201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "profile_id": 1,
                            "medicine_id": 1,
                            "title": "Курс Нурофен 3 раза в день",
                            "start_date": "2026-10-29",
                            "end_date": "2026-11-05",
                            "dosage_quantity": 1.0,
                            "rule": "после еды",
                            "time_slots": ["08:00", "14:00", "20:00"]
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/intake-courses",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-courses"]
                    }
                }
            },
            {
                "name": "Get Intake Course by ID",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/intake-courses/{{course_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-courses", "{{course_id}}"]
                    }
                }
            }
        ]
    }
    collection["item"].append(courses_folder)

    # Folder 9: Intake Logs
    logs_folder = {
        "name": "09. Intake Logs (Журнал приёмов)",
        "item": [
            {
                "name": "Today Intake Logs (На сегодня)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs?date=today",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs"],
                        "query": [{"key": "date", "value": "today"}]
                    }
                }
            },
            {
                "name": "History Logs (Пагинация)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs?history=1&page=1",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs"],
                        "query": [
                            {"key": "history", "value": "1"},
                            {"key": "page", "value": "1"}
                        ]
                    }
                }
            },
            {
                "name": "Quick Intake (Разовый приём по требованию PRN)",
                "event": [status_test([201])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "medicine_id": 1,
                            "profile_id": 1,
                            "quantity": 1.0,
                            "symptom": "Головная боль"
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs/quick",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs", "quick"]
                    }
                }
            },
            {
                "name": "Take Medicine (Отметка и списание остатка)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs/{{log_id}}/take",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs", "{{log_id}}", "take"]
                    }
                }
            },
            {
                "name": "Snooze Intake (Отложить на 15 мин)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({"minutes": 15}, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs/{{log_id}}/snooze",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs", "{{log_id}}", "snooze"]
                    }
                }
            },
            {
                "name": "Skip Intake (Пропуск с указанием причины)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({"reason": "Повысилось артериальное давление"}, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/intake-logs/{{log_id}}/skip",
                        "host": ["{{baseUrl}}"],
                        "path": ["intake-logs", "{{log_id}}", "skip"]
                    }
                }
            }
        ]
    }
    collection["item"].append(logs_folder)

    # Folder 10: Medication Safety (CDS)
    safety_folder = {
        "name": "10. Medication Safety (Клинический анализ CDS)",
        "item": [
            {
                "name": "Check Safety (Проверка рисков перед приемом)",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "POST",
                    "header": [
                        {"key": "Accept", "value": "application/json"},
                        {"key": "Content-Type", "value": "application/json"}
                    ],
                    "body": {
                        "mode": "raw",
                        "raw": json.dumps({
                            "profile_id": 1,
                            "medicine_id": 1
                        }, indent=2)
                    },
                    "url": {
                        "raw": "{{baseUrl}}/medication-safety/check",
                        "host": ["{{baseUrl}}"],
                        "path": ["medication-safety", "check"]
                    }
                }
            }
        ]
    }
    collection["item"].append(safety_folder)

    # Folder 11: Notifications
    notif_folder = {
        "name": "11. Notifications (Системные уведомления)",
        "item": [
            {
                "name": "List Notifications",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "GET",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/notifications",
                        "host": ["{{baseUrl}}"],
                        "path": ["notifications"]
                    }
                }
            },
            {
                "name": "Delete Notification",
                "event": [status_test([200])],
                "request": {
                    "auth": bearer_auth,
                    "method": "DELETE",
                    "header": [{"key": "Accept", "value": "application/json"}],
                    "url": {
                        "raw": "{{baseUrl}}/notifications/{{notification_id}}",
                        "host": ["{{baseUrl}}"],
                        "path": ["notifications", "{{notification_id}}"]
                    }
                }
            }
        ]
    }
    collection["item"].append(notif_folder)

    return collection


# --------------------------------------------------------------------------
# 3. ТАБЛИЦА СПЕЦИФИКАЦИИ API ПО ГОСТ 7.32 ДЛЯ ОТЧЕТА ВКР
# --------------------------------------------------------------------------

def build_gost_markdown_table():
    md = """# Спецификация программного интерфейса (REST API) системы «Семейная аптечка»

> **Дисциплина:** «Разработка и реализация программных систем защиты информации»  
> **Направление:** 09.04.04 Программная инженерия  
> **Автор:** Маркова И. Б.  
> **Научный руководитель:** канд. техн. наук, доц. С. А. Фирсова  
> **Базовый URL:** `http://e340043kek.temp.swtest.ru/api` (Локальный: `http://127.0.0.1:8000/api`)  
> **Спецификация OpenAPI 3.1:** `/docs` (интерактивный просмотрщик Scalar), `/openapi.json`, `/openapi.yaml`

---

## 1. Сводная таблица маршрутов REST API и механизмов защиты информации

*Таблица 1 – Реестр конечных точек REST API и политик информационной безопасности*

| № | Метод | Маршрут (URL) | Назначение операции | Уровень доступа и политики безопасности | Формат ответа (HTTP-код) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Модуль аутентификации, авторизации и управления персональными данными (152-ФЗ)** | | | | | |
| 1.1 | `POST` | `/auth/register` | Регистрация пользователя с фиксацией согласия 152-ФЗ | Публичный; Rate Limiter (`throttle:login`, 5/мин); Хеширование Argon2id; Валидация `terms_accepted` | `201 Created` / `422 Unprocessable` / `429 Too Many Requests` |
| 1.2 | `POST` | `/auth/login` | Аутентификация пользователя и выпуск Bearer-токена | Публичный; Rate Limiter (`throttle:login`, 5/мин); Проверка хеша Argon2id с защитой по времени | `200 OK` (выдача токена Sanctum) / `401 Unauthorized` / `429 Too Many Requests` |
| 1.3 | `GET` | `/auth/me` | Получение атрибутов текущего пользователя | `Bearer Sanctum`; Изоляция данных пользователя | `200 OK` (JSON: id, email, timezone, sync_enabled) / `401 Unauthorized` |
| 1.4 | `POST` | `/auth/logout` | Завершение сессии и аннулирование активного токена | `Bearer Sanctum`; Отзыв токена в БД Sanctum | `200 OK` (`Сессия успешно завершена`) |
| 1.5 | `PUT` | `/auth/password` | Смена пароля учетной записи | `Bearer Sanctum`; Проверка текущего пароля Argon2id | `200 OK` / `422 Unprocessable` |
| 1.6 | `DELETE` | `/auth/account` | Удаление аккаунта (Право на забвение 152-ФЗ) | `Bearer Sanctum`; Парольная аутентификация; Soft-Delete профилей, аптечек и пользователя | `200 OK` / `422 Unprocessable` |
| **2. Модуль сводной аналитики и показателей безопасности** | | | | | |
| 2.1 | `GET` | `/dashboard` | Агрегированные метрики аптечки и приёмов | `Bearer Sanctum`; Автоматическая фильтрация по аптечкам и профилям текущего пользователя | `200 OK` (JSON: total_medicines, expiring, expired, today_intakes) |
| **3. Модуль профилей членов семьи, учета аллергенов и патологий** | | | | | |
| 3.1 | `GET` | `/profiles` | Список профилей семьи с активными курсами | `Bearer Sanctum`; Изоляция по `user_id` | `200 OK` (массив Profile) |
| 3.2 | `POST` | `/profiles` | Создание профиля с аллергиями и диагнозами | `Bearer Sanctum`; Валидация массивов аллергенов | `201 Created` / `422 Unprocessable` |
| 3.3 | `GET` | `/profiles/{id}` | Просмотр профиля члена семьи | `Bearer Sanctum`; Проверка IDOR (`user_id == auth()->id()`) | `200 OK` / `403 Forbidden` (IDOR) |
| 3.4 | `PUT` | `/profiles/{id}` | Обновление аллергий, диагнозов и заметок | `Bearer Sanctum`; Проверка IDOR (`user_id == auth()->id()`) | `200 OK` / `403 Forbidden` |
| 3.5 | `DELETE` | `/profiles/{id}` | Удаление профиля (Soft-delete) | `Bearer Sanctum`; Проверка IDOR (`user_id == auth()->id()`) | `200 OK` / `403 Forbidden` |
| **4. Модуль управления аптечками и ролевого доступа (RBAC/ABAC)** | | | | | |
| 4.1 | `GET` | `/medicine-boxes` | Список доступных аптечек пользователя | `Bearer Sanctum`; Фильтрация по связанным ролям | `200 OK` (массив MedicineBox с pivot.role) |
| 4.2 | `POST` | `/medicine-boxes` | Создание аптечки (домашняя, авто, детская) | `Bearer Sanctum`; Назначение создателя ролью `owner` | `201 Created` |
| 4.3 | `GET` | `/medicine-boxes/{id}` | Просмотр аптечки и её содержимого | `Bearer Sanctum`; Политика `MedicineBoxPolicy::view` | `200 OK` / `403 Forbidden` |
| 4.4 | `PUT` | `/medicine-boxes/{id}` | Редактирование информации об аптечке | `Bearer Sanctum`; Политика `MedicineBoxPolicy::update` (`owner`, `editor`) | `200 OK` / `403 Forbidden` |
| 4.5 | `DELETE` | `/medicine-boxes/{id}` | Удаление аптечки | `Bearer Sanctum`; Политика `MedicineBoxPolicy::delete` (строго `owner`); Запрет удаления непустой аптечки | `200 OK` / `403 Forbidden` / `422 Unprocessable` |
| **5. Модуль семейного шеринга и управления участниками аптечки** | | | | | |
| 5.1 | `GET` | `/medicine-boxes/{id}/members` | Список участников аптечки с ролями | `Bearer Sanctum`; Политика `MedicineBoxPolicy::view` | `200 OK` (массив пользователей с ролями) |
| 5.2 | `POST` | `/medicine-boxes/{id}/members` | Добавление / смена роли участника (editor/viewer) | `Bearer Sanctum`; Gate `invite`; Проверка `sync_enabled` 152-ФЗ | `201 Created` / `200 OK` / `403 Forbidden` |
| 5.3 | `DELETE` | `/medicine-boxes/{id}/members/{user_id}` | Исключение участника из аптечки | `Bearer Sanctum`; Gate `removeMember`; Запрет удаления единственного `owner` | `200 OK` / `403 Forbidden` / `422 Unprocessable` |
| **6. Модуль учёта упаковок лекарств и сквозного шифрования (AES-256)** | | | | | |
| 6.1 | `GET` | `/medicines` | Каталог препаратов в аптечках с пагинацией | `Bearer Sanctum`; Защита от IDOR; Поиск по ГРЛС и декриптованному `custom_trade_name` | `200 OK` (пагинация `current_page`, `data`, `total`) |
| 6.2 | `POST` | `/medicines` | Добавление лекарства в аптечку | `Bearer Sanctum`; Gate `update`; AES-256 шифрование полей `custom_trade_name`, `notes` | `201 Created` / `403 Forbidden` / `422 Unprocessable` |
| 6.3 | `GET` | `/medicines/{id}` | Детальная карточка препарата | `Bearer Sanctum`; Gate `view`; Расшифровка AES-256 на лету | `200 OK` / `403 Forbidden` |
| 6.4 | `PUT` | `/medicines/{id}` | Обновление остатков, статуса и даты вскрытия | `Bearer Sanctum`; Gate `update`; Модель FEFO-Opened | `200 OK` / `403 Forbidden` / `422 Unprocessable` |
| 6.5 | `DELETE` | `/medicines/{id}` | Списание / утилизация упаковки лекарства | `Bearer Sanctum`; Gate `update` | `200 OK` / `403 Forbidden` |
| 6.6 | `POST` | `/medicines/{id}/open` | Фиксация вскрытия упаковки / флакона | `Bearer Sanctum`; Gate `update`; Модель FEFO-Opened (расчёт `effective_expiration_date`) | `200 OK` / `403 Forbidden` / `422 Unprocessable` |
| **7. Модуль открытого справочника ГРЛС / МНН** | | | | | |
| 7.1 | `GET` | `/reference-medicines` | Поиск по справочнику медикаментов (ГРЛС) | Публичный доступ; Rate Limiter (`throttle:60,1`, защита от скрапинга) | `200 OK` (массив эталонных записей) / `429 Too Many Requests` |
| 7.2 | `GET` | `/reference-medicines/{id}` | Полная карточка препарата из реестра | Публичный доступ; Rate Limiter (`throttle:60,1`) | `200 OK` / `404 Not Found` |
| **8. Модуль назначения курсов приёма лекарственных средств** | | | | | |
| 8.1 | `GET` | `/intake-courses` | Список назначенных курсов с пагинацией | `Bearer Sanctum`; Защита от IDOR по `profile_id` | `200 OK` (пагинация курсов) / `403 Forbidden` |
| 8.2 | `POST` | `/intake-courses` | Назначение курса и генерация 14-дневных логов | `Bearer Sanctum`; Gate `create`; Аудит клинической безопасности (CDS); DB Transaction | `201 Created` (включая блок `safety` и `warnings`) |
| 8.3 | `GET` | `/intake-courses/{id}` | Детали курса с расписанием и приёмами | `Bearer Sanctum`; Политика `IntakeCoursePolicy::view` | `200 OK` / `403 Forbidden` |
| 8.4 | `DELETE` | `/intake-courses/{id}` | Завершение / отмена курса приёма | `Bearer Sanctum`; Политика `IntakeCoursePolicy::delete` | `200 OK` / `403 Forbidden` |
| **9. Модуль фиксации приёмов и журнала соблюдения режима терапии** | | | | | |
| 9.1 | `GET` | `/intake-logs` | Приёмы на дату (`date=today`) или история | `Bearer Sanctum`; Защита от IDOR; Пагинация истории по 50 записей | `200 OK` (JSON: date, data либо пагинатор) |
| 9.2 | `GET` | `/intake-logs/{id}` | Просмотр отдельного события приёма | `Bearer Sanctum`; Проверка IDOR | `200 OK` / `403 Forbidden` |
| 9.3 | `POST` | `/intake-logs/quick` | Разовый приём по требованию (PRN) | `Bearer Sanctum`; Проверка IDOR; Атомарное списание остатка | `201 Created` (JSON: log, remaining_quantity) |
| 9.4 | `POST` | `/intake-logs/{id}/take` | Фиксация приёма (Take) со списанием остатка | `Bearer Sanctum`; Атомарная DB Transaction; Декремент остатка упаковки в аптечке | `200 OK` (JSON: log, remaining_quantity) |
| 9.5 | `POST` | `/intake-logs/{id}/skip` | Пропуск приёма с фиксацией причины | `Bearer Sanctum`; Проверка IDOR; Статус `skipped`, остаток не списывается | `200 OK` |
| 9.6 | `POST` | `/intake-logs/{id}/snooze` | Откладывание приёма (+15 минут по умолчанию) | `Bearer Sanctum`; Проверка IDOR; Сдвиг `scheduled_time` | `200 OK` |
| **10. Модуль клинической безопасности и поддержки решений (CDS)** | | | | | |
| 10.1 | `POST` | `/medication-safety/check` | Превентивная оценка риска назначения | `Bearer Sanctum`; Проверка IDOR; Детекция дублей МНН, аллергий и противопоказаний | `200 OK` (структурированный отчет `SafetyReport`) |
| **11. Модуль системных уведомлений и оповещений о рисках** | | | | | |
| 11.1 | `GET` | `/notifications` | Список системных уведомлений пользователя | `Bearer Sanctum`; Изоляция по `user_id` | `200 OK` (массив NotificationLog) |
| 11.2 | `DELETE` | `/notifications/{id}` | Удаление прочитанного уведомления | `Bearer Sanctum`; Проверка IDOR (`user_id == auth()->id()`) | `200 OK` / `403 Forbidden` |
| **12. Модуль аналитических отчётов комплаенса и приверженности лечению** | | | | | |
| 12.1 | `GET` | `/reports/adherence` | Отчёт о приверженности лечению (комплаенс) | `Bearer Sanctum`; Защита от IDOR; Расчёт `adherence_rate`, `streak_days`, `daily_stats` | `200 OK` / `403 Forbidden` |
| **13. Модуль администрирования, управления пользователями и IP-фильтрации (ЛР 2, Таблица 1, строка 5)** | | | | | |
| 13.1 | `GET` | `/admin/users` | Реестр пользователей и статусов блокировки | `Bearer Sanctum`; Роль `is_admin`; Строгая IP-фильтрация (только внутренняя доверенная сеть) | `200 OK` (пагинация учетных записей) / `403 Forbidden` |
| 13.2 | `POST` | `/admin/users/{id}/block` | Блокировка / разблокировка учетной записи пользователя | `Bearer Sanctum`; Роль `is_admin`; IP-фильтрация; Немедленный отзыв всех активных токенов | `200 OK` / `403 Forbidden` / `422 Unprocessable` |
| 13.3 | `POST` | `/admin/reference-medicines` | Добавление эталонного препарата в справочник ГРЛС | `Bearer Sanctum`; Роль `is_admin`; IP-фильтрация; Валидация штрихкода и АТХ | `201 Created` / `403 Forbidden` / `422 Unprocessable` |
| 13.4 | `PUT` | `/admin/reference-medicines/{id}` | Редактирование записи реестра лекарств | `Bearer Sanctum`; Роль `is_admin`; IP-фильтрация | `200 OK` / `403 Forbidden` |
| 13.5 | `DELETE` | `/admin/reference-medicines/{id}` | Удаление записи из справочника медикаментов | `Bearer Sanctum`; Роль `is_admin`; IP-фильтрация | `200 OK` / `403 Forbidden` |

---

## 2. Архитектура криптографической защиты данных (ГОСТ / OWASP)

1. **Хеширование учетных записей (Argon2id):**
   - Конфигурация: `memory_cost = 65536 KB` (64 МБ), `time_cost = 4`, `threads = 1`.
   - Обеспечивает аппаратную устойчивость к перебору на графических ускорителях (GPU/ASIC) в соответствии с рекомендациями OWASP Password Storage Cheat Sheet.
2. **Симметричное шифрование Data-at-Rest (AES-256-CBC):**
   - Поля `medicines.custom_trade_name` и `medicines.notes` защищены встроенным механизмом шифрования Laravel на базе библиотеки OpenSSL.
   - Значения в базе данных хранятся в сериализованном формате Base64 JSON с индивидуальным вектором инициализации IV и контролем целостности через HMAC SHA-256 (предотвращение атак типа Padding Oracle).
3. **Защита от атак повторного перебора и скрапинга:**
   - Для эндпоинтов входа и регистрации установлен Rate Limiter `throttle:login` (не более 5 запросов в минуту на связку IP-адрес + email) с возвратом HTTP 429 Too Many Requests;
   - Для публичного справочника лекарств ГРЛС установлен лимит `throttle:60,1` (не более 60 запросов в минуту).
"""
    return md


# --------------------------------------------------------------------------
# ОСНОВНОЙ ПАЙПЛАЙН ГЕНЕРАЦИИ
# --------------------------------------------------------------------------

def main():
    print("[1/5] Генерация OpenAPI 3.1.0 спецификации...")
    openapi_spec = build_openapi_spec()

    # Сохранение public/openapi.json
    json_path = os.path.join(PUBLIC_DIR, 'openapi.json')
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump(openapi_spec, f, ensure_ascii=False, indent=2)
    print(f"  -> Создан: {json_path}")

    # Сохранение public/openapi.yaml
    yaml_path = os.path.join(PUBLIC_DIR, 'openapi.yaml')
    with open(yaml_path, 'w', encoding='utf-8') as f:
        yaml.dump(openapi_spec, f, allow_unicode=True, sort_keys=False, default_flow_style=False)
    print(f"  -> Создан: {yaml_path}")

    print("[2/5] Генерация Postman Collection v2.1.0...")
    postman_coll = build_postman_collection()

    postman_paths = [
        os.path.join(BASE_DIR, 'FamilyApteka.postman_collection.json'),
        os.path.join(ROOT_DIR, 'FamilyApteka.postman_collection.json'),
        os.path.join(PUBLIC_DIR, 'FamilyApteka.postman_collection.json')
    ]
    for p in postman_paths:
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(postman_coll, f, ensure_ascii=False, indent=2)
        print(f"  -> Создан: {p}")

    print("[3/5] Генерация таблицы спецификации по ГОСТ 7.32...")
    gost_md = build_gost_markdown_table()
    gost_path = os.path.join(DOCS_GOST_DIR, 'API_СПЕЦИФИКАЦИЯ_ГОСТ.md')
    with open(gost_path, 'w', encoding='utf-8') as f:
        f.write(gost_md)
    print(f"  -> Создан: {gost_path}")

    print("[4/5] Валидация сгенерированных JSON-файлов...")
    with open(json_path, 'r', encoding='utf-8') as f:
        loaded_json = json.load(f)
        assert loaded_json['openapi'] == '3.1.0'
        assert len(loaded_json['paths']) >= 20
        print(f"  -> OpenAPI JSON валиден! Эндпоинтов зарегистрировано: {len(loaded_json['paths'])}")

    with open(yaml_path, 'r', encoding='utf-8') as f:
        loaded_yaml = yaml.safe_load(f)
        assert loaded_yaml['openapi'] == '3.1.0'
        print(f"  -> OpenAPI YAML валиден! Спецификация 3.1.0 подтверждена.")

    with open(postman_paths[0], 'r', encoding='utf-8') as f:
        loaded_postman = json.load(f)
        assert len(loaded_postman['item']) == 11
        print(f"  -> Postman коллекция валидна! Папок создано: {len(loaded_postman['item'])}")

    print("[5/5] Все артефакты успешно сформированы!")

if __name__ == '__main__':
    main()
