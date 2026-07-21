-- Renombra SOLO la etiqueta visible del módulo en el portal (tarjeta y nav).
-- La clave interna sigue siendo 'electoral' → rutas, permisos y asignaciones intactos.
USE portal_qro;
UPDATE modulos SET nombre = 'Seccional' WHERE clave = 'electoral';
