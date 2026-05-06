# Roadmap Jobfinder China

## Objetivo

Encontrar mas ofertas utiles en China para dos lineas principales:

- Spanish
- Business / International Business / Management / Trade / Commerce / E-commerce

El sistema debe capturar candidatos de forma amplia y puntuar despues con mas contexto, idealmente entrando en la oferta completa cuando la fuente lo permita.

## Principios

- Todo debe ser gratuito.
- Sin VPS.
- Sin APIs de pago.
- Solo ofertas en China.
- Mantener PHP + JSON por ahora.
- Playwright se usara solo como collector externo/local.
- No filtrar demasiado pronto.
- No usar penalizaciones rigidas si no aportan valor.
- Las ofertas descartadas manualmente no deben volver como nuevas.
- No intentar saltar captchas ni protecciones fuertes.

## Fase 1: Busquedas Simples y Amplias

### Spanish

Usar principalmente:

- spanish

Motivo: la palabra `spanish` suele ser suficiente para encontrar ofertas relevantes. No hace falta complicar esta busqueda con muchas variantes al principio.

### Business

Usar busquedas amplias pero controladas:

- business
- international business
- management
- trade
- commerce
- e-commerce

No usar por ahora:

- economics
- marketing
- finance

Motivo: Business es mas ambiguo que Spanish. Hay que abrir el abanico, pero sin meter areas que generen demasiado ruido.

## Fase 2: Entrar en Cada Oferta

No decidir solo con la tarjeta o listado.

Para cada oferta candidata, intentar extraer detalle:

- titulo completo
- descripcion completa
- requisitos
- asignatura
- tipo de institucion
- ubicacion
- salario si existe
- fecha
- URL original
- fuente o agencia

La puntuacion debe hacerse principalmente con el contenido completo de la oferta, no solo con el resumen del listado.

## Fase 3: Scoring Relativo

### Regla Principal

No eliminar demasiado pronto.

Primero guardar candidato, luego puntuar. Las penalizaciones deben bajar prioridad, no descartar automaticamente salvo casos claramente irrelevantes.

### Spanish

Senales positivas:

- spanish
- spanish teacher
- spanish language
- spanish lecturer
- AP Spanish
- A-Level Spanish
- IB Spanish
- IGCSE Spanish
- university
- college
- international school
- high school
- secondary school

### Business

Senales positivas:

- business
- international business
- business studies
- business management
- management
- trade
- commerce
- e-commerce
- international trade
- AP Business
- A-Level Business
- IB Business Management
- university
- college
- business school
- international school
- high school
- secondary school

### Penalizaciones

Evitar penalizar `kindergarten` y `primary` para Business por defecto.

Mejor penalizar solo cosas claramente malas:

- sales puro
- recruiter
- visa agent
- internship
- unrelated admin job
- job ad sin descripcion util
- agency sin informacion real de la posicion, solo si no aporta detalle

### Criterio Para Business

Para Business, la puntuacion debe depender casi toda de senales positivas. No se penaliza `primary` o `kindergarten` por defecto; simplemente no suman puntos si no hay senales de `business`, `management`, `trade`, `commerce` o `e-commerce`.

## Fase 4: Rechazo Manual Persistente

Crear una lista de rechazadas en JSON:

```text
backend/storage/rejected_jobs.json
```

Cuando se descarta una oferta:

- se elimina de resultados pendientes
- no se envia
- no vuelve como nueva
- no vuelve aunque aparezca en otra agencia
- se ignora en futuros scrapes si coincide por `dedupe_key`, `overlap_key`, URL o senales equivalentes

Estados deseados:

- candidata
- aceptada
- enviada
- rechazada manualmente
- ignorada por duplicado

Datos recomendados para cada rechazo:

```json
{
  "id": "...",
  "dedupe_key": "...",
  "overlap_key": "...",
  "url": "...",
  "title": "...",
  "institution": "...",
  "source": "...",
  "category": "...",
  "rejected_at": "...",
  "reason": "manual"
}
```

## Fase 5: UI de Revision

Anadir progresivamente:

- boton "Descartar"
- confirmacion simple
- razones del score
- fuentes/agencias donde aparecio
- historial de fechas
- filtro por fuente
- filtro por categoria
- filtro por nuevas

## Fase 6: Playwright Gratuito

### Opcion Inicial Recomendada

Playwright local.

Estructura posible:

```text
collectors/
  playwright/
    src/
      run.js
      hiredchina.js
      echinacities.js
      jobscina.js
    output/
      jobs.json
```

Uso:

- abrir paginas que requieren JavaScript
- entrar en detalles de ofertas
- capturar XHR/JSON si existe
- guardar un JSON normalizado para importar

No usar para:

- saltar captchas
- depender de infraestructura de pago
- sustituir todo el backend PHP

## Fase 7: Importador de Ofertas

Mas adelante, anadir endpoint:

```text
POST /api/import
```

Para importar resultados generados por Playwright local.

El backend aplicaria:

- normalizacion
- scoring
- deduplicacion
- lista de rechazadas
- guardado en JSON

## Fase 8: Fuentes China

Mantener y mejorar fuentes centradas en China:

- eChinacities
- JobsCina
- ChinaUniversityJobs
- ChinaTeachJobs
- ChinaJob
- HiredChina si Playwright local/cookies lo hacen viable
- UNNC
- webs directas de universidades en China
- webs directas de international schools en China

No priorizar por ahora:

- Jooble sin API gratuita
- LinkedIn
- portales globales con mucho ruido

## Orden Recomendado

1. Ajustar keywords y scoring para Spanish/Business.
2. Anadir rechazo manual persistente.
3. Anadir boton "Descartar".
4. Mejorar scraping de detalle donde sea posible.
5. Crear collector Playwright local.
6. Anadir importador JSON.
7. Revisar nuevas fuentes China.
8. Anadir tests minimos.
