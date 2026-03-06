# Catalogo Fornitori — Slim Framework

Applicazione web per la gestione di un catalogo fornitori/pezzi, sviluppata con **PHP + Slim 4** per il backend e **HTML/CSS/JS vanilla** per il frontend.

---

## Requisiti

- PHP 8.x
- Composer
- MySQL
- Estensioni PHP: `pdo_mysql`, `mysqli`

---

## Installazione

```bash
# 1. Clona il repo
git clone https://github.com/tuo-username/verificaasorpresaa.git
cd verificaasorpresaa

# 2. Installa le dipendenze
composer install

# 3. Avvia il server
php -S localhost:8080 index.php
```

Assicurati che il database `verificaasorpresa` esista e che la tabella `Utenti` sia configurata.  
Credenziali default: **admin / admin123**

---

## Struttura del progetto

```
verificaasorpresaa/
├── index.php        # Backend: connessione DB, helper, middleware, route /api/*
├── frontend.php     # Frontend: renderPage, dashboard, pagine query
├── vendor/          # Librerie Composer
└── composer.json
```

> `index.php` include automaticamente `frontend.php` — il punto di ingresso è sempre e solo `index.php`.

---

## Rotte frontend

| URL | Descrizione |
|-----|-------------|
| `/` | Redirect a `/frontend/q1` |
| `/frontend/q1` ... `/frontend/q10` | Le 10 query SQL del progetto |
| `/dashboard/login` | Pagina di login |
| `/dashboard/admin` | Dashboard admin (CRUD completo) |
| `/dashboard/fornitore` | Area fornitore (solo il proprio catalogo) |

---

## Rotte API

### Autenticazione
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| POST | `/api/auth/login` | Login, restituisce token JWT |
| POST | `/api/auth/register` | Crea nuovo utente *(solo admin)* |

### Pezzi
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/pezzi` | Lista paginata, ricercabile per nome |
| GET | `/api/pezzi/{id}` | Dettaglio singolo pezzo |
| POST | `/api/pezzi` | Crea pezzo *(solo admin)* |
| PUT | `/api/pezzi/{id}` | Modifica pezzo *(solo admin)* |
| DELETE | `/api/pezzi/{id}` | Elimina pezzo *(solo admin)* |

### Fornitori
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/fornitori` | Lista paginata, ricercabile per nome |
| GET | `/api/fornitori/{id}` | Dettaglio singolo fornitore |
| POST | `/api/fornitori` | Crea fornitore *(solo admin)* |
| PUT | `/api/fornitori/{id}` | Modifica fornitore *(solo admin)* |
| DELETE | `/api/fornitori/{id}` | Elimina fornitore *(solo admin)* |

### Catalogo
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/catalogo` | Lista paginata, filtrabile per `?fid=` o `?pid=` |
| GET | `/api/catalogo/{fid}/{pid}` | Dettaglio singola voce |
| POST | `/api/catalogo` | Aggiunge voce *(admin o fornitore proprietario)* |
| PUT | `/api/catalogo/{fid}/{pid}` | Modifica costo *(admin o fornitore proprietario)* |
| DELETE | `/api/catalogo/{fid}/{pid}` | Rimuove voce *(admin o fornitore proprietario)* |

### Utenti
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/utenti` | Lista utenti *(solo admin)* |
| DELETE | `/api/utenti/{id}` | Elimina utente *(solo admin)* |

### Query originali
| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/1` ... `/api/10` | Le 10 query SQL del progetto |

---

## Autenticazione

Le rotte protette richiedono un token JWT nell'header:
```
Authorization: Bearer <token>
```

Il token si ottiene facendo POST a `/api/auth/login`.

---

## Ruoli

- **admin** — accesso completo a tutto
- **fornitore** — può gestire solo le voci del proprio catalogo