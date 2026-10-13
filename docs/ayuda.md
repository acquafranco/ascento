# Ayuda de Ascento (Etapa 9.3)

## Qué hay

| Dónde | Para quién | Qué muestra |
|---|---|---|
| Panel → «Ayuda» (`/admin/ayuda`) | Dueño, administrativos y SuperAdmin dentro de una empresa | Guías paso a paso por tema, búsqueda, filtro por tema, enlace directo (`?guia=clave`) y, solo para el dueño, el uso de su plan |
| App del técnico → «Más» → «Ayuda» (`/{empresa}/ayuda`) | Técnicos | Su día (visitas, remitos, órdenes, reportes), su cuenta y problemas frecuentes |
| Portal → «Ayuda» (`/portal/ayuda`) | Clientes del portal | Qué ven, documentos, visitas, avisos y problemas frecuentes |
| Botón «?» del panel | Dueño | Explicación corta de la sección y el enlace a todas las guías |
| Tarjetas de ayuda de cada pantalla | Dueño y administrativos vigentes | Explicación corta y «Ver la guía completa», si la persona puede usar esa guía |

## Cómo se decide qué ve cada uno

- **Una sola definición:** todas las guías están en `App\Support\Help\HelpGuides`. Cada guía indica a quién está dirigida (panel, técnico o portal), qué función del plan necesita (`PlanFeature`) y, en el panel, qué área de permisos (`PermissionArea`) o si es solo del dueño.
- **Plan:** sin la función, la guía no aparece. Por ejemplo, Inicial no ve las guías de presupuestos ni del portal.
- **Permisos:** un administrativo ve solo las guías de sus áreas. Las de la cuenta (suscripción, administrativos y exportación) son del dueño.
- **Perfil y empresa:** cada ayuda vive en el grupo de rutas de su perfil, con sus middleware. Un técnico no entra a la del panel ni a la de otra empresa, y un administrativo no entra a la del técnico ni a la del portal.
- **Sin duplicar:** las explicaciones cortas de cada pantalla siguen en `AdminOnboarding::sectionHelp` y `HelpTopics`, y enlazan a la guía completa (`HelpTopics::GUIDES`).

## Cómo agregar o cambiar una guía

1. Sumala en `HelpGuides::all()`, con pasos que describan el comportamiento real y las etiquetas tal como se ven en la pantalla.
2. Si va con una pantalla nueva, sumá su ayuda corta en `HelpTopics` (`TOPICS` y `forRoute`) y su enlace en `GUIDES`.
3. La prueba `HelpGuidesTest` verifica que cada ayuda de pantalla apunte a una guía que existe.

## Decisiones

- **Botón «?»:** sigue siendo solo del dueño, porque incluye la guía de bienvenida para configurar la empresa. Los administrativos tienen la pantalla «Ayuda» y las tarjetas de cada pantalla.
- **Sin dependencias ni servicios nuevos:** las guías son texto en el código, se muestran con `<details>` (funciona sin JavaScript y en el celular) y se actualizan con cada despliegue.
