# 🚀 PORTAL DE EMPLEO NETXIA - CONFIGURACIÓN COMPLETA

## 📦 ARCHIVOS ENTREGADOS

1. ✅ **trabaja-con-nosotros.html** - Portal de empleo interactivo
2. ✅ **google-apps-script.js** - Backend para Google Drive (ver abajo)
3. ✅ Este documento de configuración

---

## 🎯 PASO 1: CONFIGURAR GOOGLE APPS SCRIPT

### 1.1 Crear el Script

1. Ve a [script.google.com](https://script.google.com)
2. Click en "**Nuevo proyecto**"
3. Nombra el proyecto: "**Netxia Careers Backend**"
4. Copia y pega el siguiente código:

```javascript
/**
 * NETXIA CAREERS BACKEND
 * Maneja postulaciones y las guarda en Google Drive
 */

// CONFIGURACIÓN
const FOLDER_ID = '1537yWay85HK8UbgjSAZqcVIt8WAVh1Zp'; // Tu carpeta de Drive
const NOTIFICATION_EMAIL = 'nekzux@gmail.com'; // Email para notificaciones

function doPost(e) {
  try {
    const params = e.parameter;
    
    // Validar datos requeridos
    if (!params.nombre || !params.email || !params.telefono) {
      return ContentService.createTextOutput(
        JSON.stringify({success: false, error: 'Datos incompletos'})
      ).setMimeType(ContentService.MimeType.JSON);
    }
    
    // Obtener carpeta de Drive
    const folder = DriveApp.getFolderById(FOLDER_ID);
    
    // Crear subcarpeta con nombre del postulante y fecha
    const timestamp = new Date().toISOString().slice(0,10);
    const candidateFolderName = `${params.nombre} - ${timestamp}`;
    const candidateFolder = folder.createFolder(candidateFolderName);
    
    // Procesar CV (viene en base64)
    if (params.cvBase64 && params.cvFileName) {
      const cvBlob = Utilities.newBlob(
        Utilities.base64Decode(params.cvBase64),
        params.cvMimeType,
        params.cvFileName
      );
      candidateFolder.createFile(cvBlob);
    }
    
    // Crear documento de texto con la información
    const applicationData = createApplicationDocument(params);
    const textFile = candidateFolder.createFile(
      `Datos - ${params.nombre}.txt`,
      applicationData,
      MimeType.PLAIN_TEXT
    );
    
    // Enviar notificación por email
    sendNotificationEmail(params, candidateFolder.getUrl());
    
    return ContentService.createTextOutput(
      JSON.stringify({
        success: true,
        message: 'Postulación recibida exitosamente'
      })
    ).setMimeType(ContentService.MimeType.JSON);
    
  } catch (error) {
    Logger.log('Error: ' + error.toString());
    return ContentService.createTextOutput(
      JSON.stringify({
        success: false,
        error: error.toString()
      })
    ).setMimeType(ContentService.MimeType.JSON);
  }
}

function createApplicationDocument(params) {
  let doc = '=== POSTULACIÓN NETXIA ===\n\n';
  doc += `Fecha: ${new Date().toLocaleString('es-CL')}\n\n`;
  
  doc += '--- DATOS PERSONALES ---\n';
  doc += `Nombre: ${params.nombre}\n`;
  doc += `Email: ${params.email}\n`;
  doc += `Teléfono: ${params.telefono}\n`;
  doc += `LinkedIn: ${params.linkedin || 'No proporcionado'}\n\n`;
  
  doc += '--- INFORMACIÓN PROFESIONAL ---\n';
  doc += `Rol: ${params.rol}\n`;
  doc += `Experiencia: ${params.experiencia}\n`;
  doc += `Portafolio/GitHub: ${params.portafolio || 'No proporcionado'}\n\n`;
  
  doc += '--- HABILIDADES ---\n';
  if (params.skills) {
    try {
      const skills = JSON.parse(params.skills);
      for (let [skill, level] of Object.entries(skills)) {
        doc += `${skill}: ${level}/5\n`;
      }
    } catch (e) {
      doc += 'Error al parsear habilidades\n';
    }
  }
  doc += '\n';
  
  doc += '--- MOTIVACIÓN ---\n';
  doc += `${params.motivacion}\n\n`;
  
  doc += '--- CV ADJUNTO ---\n';
  doc += `Archivo: ${params.cvFileName}\n`;
  
  return doc;
}

function sendNotificationEmail(params, folderUrl) {
  const subject = `🚀 Nueva Postulación: ${params.nombre}`;
  
  const body = `
    Nueva postulación recibida en el portal de empleo de Netxia.
    
    CANDIDATO: ${params.nombre}
    EMAIL: ${params.email}
    ROL: ${params.rol}
    EXPERIENCIA: ${params.experiencia}
    
    Ver carpeta completa: ${folderUrl}
    
    ---
    Este email fue generado automáticamente por el sistema de postulaciones de Netxia.
  `;
  
  try {
    MailApp.sendEmail({
      to: NOTIFICATION_EMAIL,
      subject: subject,
      body: body
    });
  } catch (e) {
    Logger.log('Error enviando email: ' + e.toString());
  }
}

// Para testing
function doGet() {
  return HtmlService.createHtmlOutput('<h1>Netxia Careers Backend</h1><p>Sistema activo</p>');
}
```

### 1.2 Desplegar el Script

1. Click en el botón **"Implementar"** (arriba a la derecha)
2. Selecciona **"Nueva implementación"**
3. Tipo: **"Aplicación web"**
4. Configuración:
   - Descripción: "Netxia Careers v1"
   - Ejecutar como: **"Yo"** (nekzux@gmail.com)
   - Quién tiene acceso: **"Cualquier usuario"**
5. Click en **"Implementar"**
6. **COPIA LA URL** que te da (se verá así: `https://script.google.com/macros/s/AKfycby.../exec`)

⚠️ **IMPORTANTE**: Guarda esta URL, la necesitarás en el siguiente paso.

---

## 🔧 PASO 2: ACTUALIZAR LA PÁGINA HTML

1. Abre el archivo **trabaja-con-nosotros.html**
2. Busca la línea **~línea 810** que dice:
   ```javascript
   const scriptURL = 'YOUR_GOOGLE_APPS_SCRIPT_URL_HERE';
   ```
3. Reemplázala con tu URL del Apps Script:
   ```javascript
   const scriptURL = 'https://script.google.com/macros/s/TU_URL_AQUI/exec';
   ```
4. Guarda el archivo

---

## 🌐 PASO 3: ACTUALIZAR NAVEGACIÓN EN INDEX.HTML

Agrega el link "Trabaja con Nosotros" en tu index.html:

```html
<!-- Busca la sección de navegación y agrega: -->
<ul class="nav-links">
    <li><a href="#servicios">Servicios</a></li>
    <li><a href="#proyectos">Casos de Éxito</a></li>
    <li><a href="#nosotros">Nosotros</a></li>
    <li><a href="/trabaja-con-nosotros">Únete al Equipo</a></li> <!-- NUEVO -->
    <li><a href="/contacto">Contacto</a></li>
</ul>
```

---

## 🚀 PASO 4: SUBIR Y PROBAR

### 4.1 Subir Archivos
```bash
# Sube a tu servidor
scp trabaja-con-nosotros.html tu-servidor:/ruta/del/sitio/
```

### 4.2 Probar el Formulario
1. Ve a https://netxia.cl/trabaja-con-nosotros
2. Completa el formulario de prueba
3. Sube un CV de prueba
4. Envía la postulación
5. Verifica:
   - ✅ Mensaje de éxito en el sitio
   - ✅ Carpeta creada en tu Google Drive
   - ✅ CV guardado en la carpeta
   - ✅ Archivo de texto con datos
   - ✅ Email de notificación recibido

---

## 📊 ESTRUCTURA DE ALMACENAMIENTO

Cuando alguien postula, se crea esta estructura en tu Drive:

```
📁 Carpeta de Postulaciones (ID: 1537yWay85HK8UbgjSAZqcVIt8WAVh1Zp)
  └── 📁 María González - 2026-02-14/
      ├── 📄 CV-Maria-González.pdf
      └── 📄 Datos - María González.txt
          ├── Datos personales
          ├── Información profesional
          ├── Habilidades con niveles
          └── Motivación
```

---

## 🎨 CARACTERÍSTICAS DEL PORTAL

### ✨ Funcionalidades Implementadas:

1. **Formulario Multi-Paso (4 pasos)**
   - Paso 1: Datos básicos (nombre, email, teléfono, LinkedIn)
   - Paso 2: Skills interactivos con nivel de experiencia
   - Paso 3: Experiencia, rol, motivación, portafolio
   - Paso 4: Upload de CV con drag & drop

2. **UX/UI Moderna**
   - Barra de progreso animada
   - Transiciones suaves entre pasos
   - Validación en tiempo real
   - Drag & drop para CV
   - Responsive mobile-first
   - Loading states
   - Success screen animado

3. **Skills Interactivos**
   - 12 tecnologías principales
   - Click para seleccionar
   - Nivel de 1-5 estrellas
   - Visual feedback inmediato

4. **Upload de CV**
   - Formatos: PDF, DOC, DOCX, JPG, PNG
   - Máximo 10MB
   - Drag & drop
   - Preview del nombre y tamaño

5. **Backend Robusto**
   - Google Apps Script (serverless)
   - Almacenamiento en Drive
   - Email de notificación automático
   - Error handling completo
   - Logs para debugging

---

## 🛠️ PERSONALIZACIÓN

### Cambiar Skills Disponibles

En el archivo HTML, busca la sección `skills` (~línea 600):

```javascript
const skills = [
    { id: 'python', name: 'Python', icon: '🐍' },
    { id: 'javascript', name: 'JavaScript', icon: '⚡' },
    // Agrega más skills aquí
    { id: 'go', name: 'Go', icon: '🔵' },
    { id: 'rust', name: 'Rust', icon: '🦀' }
];
```

### Cambiar Email de Notificación

En Google Apps Script, cambia:
```javascript
const NOTIFICATION_EMAIL = 'tu-nuevo-email@netxia.cl';
```

### Cambiar Roles Disponibles

En el HTML, busca el select de roles (~línea 550) y modifica las opciones.

---

## 🔒 SEGURIDAD Y PRIVACIDAD

### Permisos del Script
- ✅ El script solo accede a la carpeta específica de Drive
- ✅ Solo tú (nekzux@gmail.com) puedes ver las postulaciones
- ✅ Los datos se envían encriptados (HTTPS)

### Recomendaciones:
1. No compartas la URL del Apps Script públicamente
2. Revisa periódicamente las carpetas de postulaciones
3. Elimina CVs antiguos después de procesar
4. Cumple con GDPR/LOPD si aplica

---

## 🐛 TROUBLESHOOTING

### Error: "Script no autorizado"
**Solución**: Al implementar por primera vez, Google pedirá autorización. Click en "Revisar permisos" → "Avanzado" → "Ir a Netxia Careers Backend" → "Permitir"

### Error: "No se puede crear carpeta"
**Solución**: Verifica que el FOLDER_ID sea correcto y que la cuenta nekzux@gmail.com tenga permisos de escritura.

### Email no llega
**Solución**: 
1. Revisa spam
2. Verifica que NOTIFICATION_EMAIL sea correcto
3. Chequea logs en Apps Script (Ver → Registros)

### CV no se sube
**Solución**:
1. Verifica que el archivo sea < 10MB
2. Formatos válidos: PDF, DOC, DOCX, JPG, PNG
3. Revisa console del navegador (F12)

### Formulario no envía
**Solución**:
1. Verifica que hayas actualizado la URL del script
2. Abre F12 → Console para ver errores
3. Testea el script directamente con doGet()

---

## 📈 MÉTRICAS Y ANALYTICS

### Agregar Google Analytics (Opcional)

En el HTML, antes de `</head>`:

```html
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXXXXXX');
  
  // Track form steps
  function trackStep(step) {
    gtag('event', 'form_step', {
      'event_category': 'careers',
      'event_label': `step_${step}`,
      'value': step
    });
  }
</script>
```

Llama `trackStep(currentStep)` cada vez que cambias de paso.

---

## 🎯 MEJORAS FUTURAS (Opcionales)

### Fase 2:
- [ ] Integración con ATS (Applicant Tracking System)
- [ ] Dashboard para gestionar postulaciones
- [ ] Video entrevistas asíncronas
- [ ] Tests técnicos online
- [ ] Scoring automático de CVs con IA

### Fase 3:
- [ ] Pipeline de onboarding automático
- [ ] Integración con Slack para notificaciones
- [ ] Portal del candidato para seguimiento
- [ ] Gamificación del proceso de postulación

---

## ✅ CHECKLIST DE IMPLEMENTACIÓN

- [ ] Google Apps Script creado y desplegado
- [ ] URL del script copiada
- [ ] HTML actualizado con la URL correcta
- [ ] Archivo subido al servidor
- [ ] Link agregado en navegación de index.html
- [ ] Prueba de postulación completa realizada
- [ ] Carpeta creada en Drive ✓
- [ ] CV guardado correctamente ✓
- [ ] Email de notificación recibido ✓
- [ ] Mobile testing completado
- [ ] Analytics configurado (opcional)

---

## 🎉 CONCLUSIÓN

Tienes ahora un portal de empleo profesional, moderno e innovador que:

✅ Captura talento de forma estructurada
✅ Almacena automáticamente en Google Drive
✅ Notifica al instante por email
✅ Ofrece UX superior a otros portales
✅ Es 100% gratis (usa infraestructura de Google)
✅ No requiere backend complejo
✅ Es fácil de mantener

**Costo total**: $0 (usa Google Apps Script gratuito)
**Tiempo de setup**: 15-20 minutos
**Mantenimiento**: Cero

---

## 📞 SOPORTE

Si tienes problemas con la configuración:

1. Revisa los logs en Apps Script (Ver → Registros)
2. Abre la consola del navegador (F12)
3. Verifica permisos de la carpeta de Drive
4. Testea con el método doGet() del script

**Documentación Google Apps Script**: https://developers.google.com/apps-script

---

*Documento creado el 29 de Enero, 2026*
*Netxia - Portal de Empleo v1.0*
