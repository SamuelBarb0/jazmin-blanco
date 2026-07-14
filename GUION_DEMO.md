# Guión de demostración — Dra. Jasmin Blanco

Objetivo de la reunión: **mostrarle a la doctora el sistema** (su CRM + el asistente con IA) y cómo desde un solo panel controlará las conversaciones que Claude responde, alimentadas por la información que ella carga.

---

## Antes de empezar (preparación)

1. Tener el sistema corriendo. En la carpeta del proyecto:
   ```
   composer run dev
   ```
   (levanta el servidor + assets). Abrir en el navegador la URL que indique (normalmente `http://127.0.0.1:8000`).
2. Iniciar sesión: **jazmin@consultorio.test** / **jazmin2026**.
3. **Recomendado: activar modo oscuro** (menú de apariencia, arriba a la derecha) — el diseño "Liquid Glass" se ve más impactante.
4. Tener listas estas pantallas en pestañas o saber el orden del recorrido.

> Nota honesta para ti: el bot **responde en vivo solo cuando esté la API key de Anthropic**. La conversación del Asistente ya está sembrada como **ejemplo real** para mostrar la experiencia aunque la key todavía no esté. Si la doctora escribe en vivo sin key, saldrá un aviso de "activar la IA" — explícalo como el último paso pendiente.

---

## Recorrido (orden sugerido)

### 1. Dashboard — "el centro de comando"
Es lo primero que verá al entrar.
- **Qué decir:** "Este es tu panel principal. De un vistazo ves cuántos pacientes (leads) tienes, cuántas citas agendadas, y de qué canal llegan (WhatsApp, Instagram, Meta Ads)."
- Señalar: el saludo con su nombre, las **métricas en vivo**, la **conversión por canal** y el **embudo (pipeline)** por etapa.
- Mencionar que las métricas se llenan solas a medida que entran pacientes.

### 2. Pipeline — "el embudo de pacientes"
- **Qué decir:** "Aquí ves a cada paciente potencial y en qué etapa está: nuevo, calificando, agendado, en valoración, cerrado… Igual que un tablero, pero hecho a tu medida."
- **Demostrar:** **arrastrar una tarjeta** de una columna a otra (ej. de "Nuevo" a "Interesado"). Se guarda solo. Es el gesto que más impresiona.
- Señalar las etiquetas de colores, el canal y el valor de cada paciente.

### 3. Pacientes — "la lista completa"
- **Qué decir:** "Si prefieres una vista de lista, aquí están todos tus pacientes con buscador y filtros por etapa. Puedes editar a cada uno, ver su canal, etiquetas y valor."
- Demostrar los **filtros por etapa** (los chips de arriba).

### 4. Asistente — ⭐ **el corazón de la demo**
- **Qué decir:** "Este es el asistente con inteligencia artificial (Claude). Responde a tus pacientes por WhatsApp e Instagram con un trato humano, usando TODA la información de tu clínica."
- **Mostrar la conversación de ejemplo** que ya está cargada: la paciente pregunta por el Endolifting → el bot explica, da precio referencial, aclara que la valoración define el plan, tranquiliza sobre el dolor y la **lleva a agendar**.
- Recalcar: "Fíjate que **nunca inventa precios ni promete resultados**, siempre invita a la valoración médica — cumple la normativa (Invima, habeas data)."
- Explicar el indicador **"X fuentes"**: el bot responde con base en tus servicios y tu conocimiento.

### 5. Conocimiento — "lo que el bot sabe"
- **Qué decir:** "Aquí controlas exactamente qué sabe el bot: precios, qué incluye la valoración, contraindicaciones, preguntas frecuentes… Tú lo editas en lenguaje normal, sin tocar nada técnico, y el bot aprende al instante."
- Demostrar: agregar una entrada rápida (ej. una FAQ) y mostrar que aparece en la lista.

### 6. Servicios — "tu catálogo"
- **Qué decir:** "Tus servicios (Endolifting, FUE, Programa Metabólico…). Cada uno con su precio y duración. Y hay un botón **'Generar con IA'** que redacta la descripción profesional por ti."
- (Si hay key: demostrar el botón en vivo. Si no: explicarlo.)

### 7. Configuración → Integración IA — "el único paso que falta"
- **Qué decir:** "Para encender el cerebro del bot, solo se pega aquí la clave de Anthropic y listo — todo lo demás ya está hecho. También aquí defines el tono del bot y los datos de tu clínica."

---

## Mensaje de cierre

> "Todo esto es **tuyo**: tus datos, tu sistema, sin suscripciones eternas. Hoy ya están listos el panel, el pipeline, el conocimiento y el asistente. El siguiente paso es **activar la IA con la clave** y conectar WhatsApp e Instagram para que empiece a atender solo."

## Lo que sigue (si preguntan)
1. Conseguir la **API key de Anthropic** (cuenta a nombre de la clínica).
2. Conectar **WhatsApp Business + Instagram** (cuentas de Meta de la doctora).
3. **Agendamiento con Google Calendar**.
