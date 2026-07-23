<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Catálogo real de servicios de POLARIX (clínica de la Dra. Jasmin Blanco).
 *
 * Fuentes:
 *  - "SERVICIOS CONTABILIDAD AETERNUM.xlsx" → código, categoría y precio de referencia.
 *  - "POLARIX.docx" → descripciones clínicas (alimentan ai_context para el bot RAG).
 *
 * Reemplaza por completo los servicios del usuario de la clínica.
 */
class PolarixServicesSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstWhere('email', 'jazmin@consultorio.test');
        if (! $user) {
            $this->command?->warn('No existe el usuario jazmin@consultorio.test; se omite PolarixServicesSeeder.');

            return;
        }

        // Reemplazo limpio: borra el catálogo anterior (demo) y carga el real.
        $user->services()->delete();

        $sort = 0;
        foreach ($this->services() as $s) {
            $user->services()->create([
                'name' => $s['name'],
                'category' => $s['category'],
                'price' => $s['price'],
                'short_description' => $s['short'],
                'ai_context' => $s['ai'] ?? null,
                'is_active' => true,
                'sort_order' => $sort++,
            ]);
        }

        $this->command?->info("POLARIX: {$sort} servicios cargados para {$user->email}.");
    }

    /**
     * @return array<int,array{name:string,category:string,price:int|null,short:string,ai?:string}>
     */
    private function services(): array
    {
        $toxina = 'La toxina botulínica relaja de forma controlada los músculos responsables de las líneas de expresión (frente, entrecejo y patas de gallina). Suaviza las arrugas y previene que se profundicen, logrando un rostro descansado y natural sin perder la expresión. Los resultados se notan entre 3 y 7 días y duran aproximadamente de 4 a 6 meses. Siempre requiere valoración médica previa para definir dosis, zonas y técnica según la anatomía de cada paciente. Incluye cita de control y retoque.';

        $ah = 'El ácido hialurónico es una sustancia biocompatible que permite restaurar volumen, definir contornos y armonizar el rostro de forma segura y natural. Siempre se requiere valoración médica previa para definir técnica, cantidad y tipo de producto.';

        $bio = 'Los bioestimuladores activan la producción natural de colágeno, mejorando firmeza, elasticidad y calidad de la piel de forma progresiva. No todos los pacientes son candidatos al mismo producto: se requiere valoración médica para elegir la molécula ideal según tipo de piel, edad, condiciones médicas y objetivo estético.';

        $reductor = 'Protocolo médico para reducción de medidas y contorno corporal que combina hidrolipoclasia, mesoterapia corporal y aparatología. El plan se diseña según valoración médica y los objetivos de cada paciente.';

        return [
            // ── Toxina botulínica ──────────────────────────────
            ['name' => 'Toxina Botulínica · Neuronox', 'category' => 'Medicina Estética', 'price' => 800000,
                'short' => 'Aplicación de toxina botulínica (Neuronox) para suavizar líneas de expresión. Incluye control y retoque.',
                'ai' => $toxina."\n\nMarca: Neuronox."],
            ['name' => 'Toxina Botulínica · Botox (Allergan)', 'category' => 'Medicina Estética', 'price' => 1000000,
                'short' => 'Aplicación de toxina botulínica Botox (Allergan) para líneas de expresión. Incluye control y retoque.',
                'ai' => $toxina."\n\nMarca: Botox (Allergan)."],
            ['name' => 'Toxina Botulínica · Dysport', 'category' => 'Medicina Estética', 'price' => 1100000,
                'short' => 'Aplicación de toxina botulínica Dysport para líneas de expresión. Incluye control y retoque.',
                'ai' => $toxina."\n\nMarca: Dysport."],

            // ── Ácido hialurónico ──────────────────────────────
            ['name' => 'Ácido Hialurónico · Rinomodelación', 'category' => 'Medicina Estética', 'price' => 1000000,
                'short' => 'Corrige imperfecciones de la nariz sin cirugía, mejorando el perfil de forma inmediata y natural.',
                'ai' => $ah."\n\nRinomodelación: corrige visualmente imperfecciones de la nariz sin cirugía, mejorando el perfil de forma inmediata y natural."],
            ['name' => 'Ácido Hialurónico · Perfilamiento de labios', 'category' => 'Medicina Estética', 'price' => 1000000,
                'short' => 'Define, hidrata y da volumen a los labios respetando la anatomía facial, evitando resultados artificiales.',
                'ai' => $ah."\n\nVoluminización y perfilamiento de labios: define, hidrata y da volumen respetando la anatomía facial, evitando resultados artificiales."],
            ['name' => 'Ácido Hialurónico · Perfilamiento facial', 'category' => 'Medicina Estética', 'price' => null,
                'short' => 'Mejora pómulos, mentón y línea mandibular para un rostro más definido y equilibrado. Precio según número de jeringas.',
                'ai' => $ah."\n\nPerfilamiento facial: mejora pómulos, mentón y línea mandibular para un rostro más definido y equilibrado. El valor final depende del número de jeringas de ácido hialurónico requeridas en el paciente."],
            ['name' => 'Ácido Hialurónico · Feminización / Masculinización facial', 'category' => 'Medicina Estética', 'price' => null,
                'short' => 'Resalta rasgos femeninos o masculinos de forma armónica y elegante. Precio según número de jeringas.',
                'ai' => $ah."\n\nFeminización y masculinización facial: procedimientos diseñados para resaltar rasgos femeninos o masculinos de forma armónica y elegante. El valor final depende del número de jeringas requeridas."],

            // ── Mesoterapia / hidratación facial ───────────────
            ['name' => 'Mesoterapia Facial · Sunekos', 'category' => 'Mesoterapia', 'price' => 700000,
                'short' => 'Hidratación facial que estimula colágeno y elastina. Ideal para pieles deshidratadas o con flacidez. Desde $700.000.',
                'ai' => 'La hidratación facial con mesoterapia mejora la calidad de la piel desde el interior, aportando hidratación profunda, luminosidad y elasticidad. Sunekos estimula colágeno y elastina, ideal para pieles deshidratadas o con flacidez. Requiere valoración médica previa.'],
            ['name' => 'Mesoterapia Facial · Profhilo', 'category' => 'Mesoterapia', 'price' => 1800000,
                'short' => 'Bio-remodelación intensa para pieles envejecidas o apagadas.',
                'ai' => 'Profhilo es una bio-remodelación intensa indicada para pieles envejecidas o apagadas, que mejora hidratación, firmeza y luminosidad. Requiere valoración médica previa.'],
            ['name' => 'Mesoterapia Facial · Jalupro Classic', 'category' => 'Mesoterapia', 'price' => 700000,
                'short' => 'Mejora textura, firmeza y tono de la piel. Valor por sesión.',
                'ai' => 'Jalupro Classic mejora textura, firmeza y tono de la piel. Tratamiento médico de revitalización; requiere valoración médica previa.'],
            ['name' => 'Mesoterapia Facial · DR Deep Rejuvenation', 'category' => 'Mesoterapia', 'price' => 1200000,
                'short' => 'Revitalización profunda con efecto glow. Valor por sesión.',
                'ai' => 'DR Deep Rejuvenation es una revitalización profunda con efecto glow inmediato. Servicio médico; requiere valoración previa.'],
            ['name' => 'Mesoterapia Facial · NCTF 135 HA', 'category' => 'Mesoterapia', 'price' => 600000,
                'short' => 'Revitalización con ácido hialurónico + 50 activos (vitaminas, aminoácidos, antioxidantes). Vial completo 3 ml.',
                'ai' => 'NCTF 135 HA combina ácido hialurónico no reticulado con un complejo de más de 50 activos esenciales (vitaminas, aminoácidos, minerales, coenzimas y antioxidantes). Mejora hidratación, brillo, textura, tono y firmeza, estimula la regeneración celular y atenúa líneas finas. Indicado para pieles opacas, deshidratadas, con fatiga, daño solar o envejecimiento temprano. Resultados progresivos potenciados con un protocolo de varias sesiones. Valor $600.000 por vial completo de 3 ml. Requiere valoración médica previa.'],
            ['name' => 'Mesoterapia Facial · NCTF Ojeras', 'category' => 'Mesoterapia', 'price' => 350000,
                'short' => 'Protocolo específico para ojeras (aplicación 1,5 ml por sesión).',
                'ai' => 'Protocolo específico de NCTF para el área de las ojeras. Aplicación por protocolo de 1,5 ml. Valor de la sesión $350.000. Requiere valoración médica previa.'],
            ['name' => 'Mesoterapia Capilar', 'category' => 'Mesoterapia', 'price' => 280000,
                'short' => 'Fortalece el folículo, reduce la caída y estimula el crecimiento del cabello.',
                'ai' => 'La mesoterapia capilar fortalece el folículo, reduce la caída y estimula el crecimiento del cabello. Cada protocolo se define tras valoración médica.'],
            ['name' => 'Mesoterapia Corporal Reductora', 'category' => 'Mesoterapia', 'price' => 120000,
                'short' => 'Apoya la reducción de grasa localizada y mejora la textura de la piel.',
                'ai' => 'La mesoterapia corporal apoya la reducción de grasa localizada y mejora la textura de la piel. Forma parte de protocolos médicos reductores. Cada protocolo se define tras valoración médica.'],

            // ── Bioestimuladores ───────────────────────────────
            ['name' => 'Bioestimulador · Sculptra', 'category' => 'Bioestimuladores', 'price' => 2500000,
                'short' => 'Ácido Poli-L-Láctico. Estimula colágeno de forma progresiva; ideal para flacidez y pérdida de volumen.',
                'ai' => $bio."\n\nSculptra (Ácido Poli-L-Láctico): estimula colágeno de forma progresiva, ideal para flacidez y pérdida de volumen."],
            ['name' => 'Bioestimulador · Rennova Elleva', 'category' => 'Bioestimuladores', 'price' => 2500000,
                'short' => 'Ácido Poli-L-Láctico. Estimula colágeno de forma progresiva; ideal para flacidez y pérdida de volumen.',
                'ai' => $bio."\n\nRennova Elleva (Ácido Poli-L-Láctico): estimula colágeno de forma progresiva, ideal para flacidez y pérdida de volumen."],
            ['name' => 'Bioestimulador · Radiesse', 'category' => 'Bioestimuladores', 'price' => 1800000,
                'short' => 'Hidroxiapatita de Calcio. Soporte inmediato y estimulación de colágeno; mejora contorno y firmeza.',
                'ai' => $bio."\n\nRadiesse (Hidroxiapatita de Calcio): aporta soporte inmediato y estimula colágeno, mejorando contorno y firmeza."],
            ['name' => 'Bioestimulador · Rennova Diamond', 'category' => 'Bioestimuladores', 'price' => 1500000,
                'short' => 'Hidroxiapatita de Calcio. Soporte inmediato y estimulación de colágeno; mejora contorno y firmeza.',
                'ai' => $bio."\n\nRennova Diamond (Hidroxiapatita de Calcio): aporta soporte inmediato y estimula colágeno, mejorando contorno y firmeza."],
            ['name' => 'Bioestimulador · HArmonyCa', 'category' => 'Bioestimuladores', 'price' => 3800000,
                'short' => 'Ácido hialurónico + hidroxiapatita de calcio: volumen inmediato y bioestimulación progresiva.',
                'ai' => $bio."\n\nHArmonyCa combina ácido hialurónico e hidroxiapatita de calcio para volumen inmediato y bioestimulación progresiva."],

            // ── Armonización ───────────────────────────────────
            ['name' => 'Armonización Facial', 'category' => 'Medicina Estética', 'price' => null,
                'short' => 'Plan médico integral y personalizado que combina varios procedimientos. Precio según el plan diseñado.',
                'ai' => 'La armonización facial es un plan médico integral y personalizado que combina diferentes procedimientos para mejorar proporciones, contornos y calidad de la piel. Puede incluir toxina botulínica, ácido hialurónico, bioestimuladores y tecnologías médicas según cada caso. No es un tratamiento estándar: siempre inicia con una valoración médica para diseñar el plan adecuado.'],

            // ── Tecnología médica ──────────────────────────────
            ['name' => 'Radiofrecuencia Fraccionada', 'category' => 'Tecnología Médica', 'price' => 800000,
                'short' => 'Estimula colágeno y elastina; mejora flacidez, textura y poros. Valor por sesión.',
                'ai' => 'La radiofrecuencia fraccionada es una tecnología médica que estimula la producción de colágeno y elastina, mejorando flacidez, textura de la piel y apariencia de los poros, con efecto rejuvenecedor progresivo sin cirugía. Requiere valoración médica para definir zonas y número de sesiones.'],
            ['name' => 'Láser IPL (Luz Pulsada Intensa)', 'category' => 'Tecnología Médica', 'price' => 350000,
                'short' => 'Manejo de manchas, rosácea, acné, rejuvenecimiento y unificación del tono. Desde $350.000 por sesión.',
                'ai' => 'La luz pulsada intensa (IPL) es un tratamiento médico versátil con múltiples aplicaciones: manchas, rosácea, acné, rejuvenecimiento facial y unificación del tono de la piel. Se realiza únicamente tras valoración médica para garantizar seguridad y resultados adecuados.'],
            ['name' => 'Exilis 360 · Facial', 'category' => 'Tecnología Médica', 'price' => 200000,
                'short' => 'Radiofrecuencia + ultrasonido no invasivo: reafirma la piel y mejora el contorno facial. Valor por sesión.',
                'ai' => 'Exilis 360 es una tecnología no invasiva que combina radiofrecuencia y ultrasonido para reafirmar la piel, reducir grasa localizada y mejorar el contorno facial. Resultados progresivos y seguros.'],
            ['name' => 'Exilis 360 · Corporal', 'category' => 'Tecnología Médica', 'price' => 200000,
                'short' => 'Radiofrecuencia + ultrasonido no invasivo: reafirma, reduce grasa localizada y mejora el contorno corporal. Valor por sesión.',
                'ai' => 'Exilis 360 corporal combina radiofrecuencia y ultrasonido para reafirmar la piel, reducir grasa localizada y mejorar el contorno corporal. Resultados progresivos y seguros.'],
            ['name' => 'Exilis 360 · Paquete 8 sesiones', 'category' => 'Tecnología Médica', 'price' => 1400000,
                'short' => 'Paquete de 8 sesiones (valor sesión $175.000). Incluye 1 sesión de mesoterapia de reducción o reafirmante de obsequio.',
                'ai' => 'Paquete de 8 sesiones de Exilis 360 a $1.400.000 (valor sesión $175.000). Incluye de obsequio una sesión de mesoterapia de reducción o reafirmante.'],

            // ── Programas y paquetes ───────────────────────────
            ['name' => 'Programa Reset Metabolismo 360', 'category' => 'Medicina Funcional', 'price' => 850000,
                'short' => 'Programa médico integral de reducción de peso que trata la causa del sobrepeso y evita el efecto rebote. Desde $850.000/mes.',
                'ai' => 'Programa médico integral para reducción de peso enfocado en tratar la causa real del sobrepeso y evitar el efecto rebote. Incluye valoración médica, plan personalizado, acompañamiento profesional y, cuando está indicado, uso de péptidos para control de ansiedad y regulación metabólica. No es un programa genérico: requiere valoración médica para determinar si el paciente es candidato. Desde $850.000 mensual (medicamento para control de ansiedad y reducción de peso); desde $1.000.000 mensual con 2 sesiones de sueroterapia para reset metabólico.'],
            ['name' => 'Paquetes Reductores', 'category' => 'Tratamientos Médicos', 'price' => 1400000,
                'short' => 'Combinan hidrolipoclasia, mesoterapia corporal y aparatología para mejorar medidas y contorno. Según valoración.',
                'ai' => $reductor],

            // ── Capilar / regenerativa ─────────────────────────
            ['name' => 'Implante Capilar · Técnica FUE', 'category' => 'Cirugía Médica', 'price' => 6000000,
                'short' => 'Restauración capilar con folículos propios; resultados naturales y permanentes. Desde $6.000.000 (2.500 UF). Financiación Meddipay.',
                'ai' => 'Procedimiento médico avanzado de restauración capilar mediante la técnica FUE, utilizando folículos propios del paciente. Ofrece resultados naturales, progresivos y permanentes. Requiere valoración médica previa. Valores desde $6.000.000 (2.500 unidades foliculares). Contamos con sistema de financiación con Meddipay.'],
            ['name' => 'Plasma Rico en Plaquetas · Facial', 'category' => 'Medicina Regenerativa', 'price' => 180000,
                'short' => 'Mejora regeneración, luminosidad y calidad de la piel usando el propio plasma del paciente.',
                'ai' => 'El PRP facial mejora la regeneración, luminosidad y calidad de la piel. Se obtiene de la propia sangre del paciente y requiere valoración médica.'],
            ['name' => 'Plasma Rico en Plaquetas · Capilar', 'category' => 'Medicina Regenerativa', 'price' => 250000,
                'short' => 'Fortalece el folículo, reduce la caída y estimula el crecimiento del cabello con el propio plasma.',
                'ai' => 'El PRP capilar fortalece el folículo, reduce la caída y estimula el crecimiento del cabello. Se obtiene de la propia sangre del paciente y requiere valoración médica.'],
            ['name' => 'Exosomas Faciales', 'category' => 'Medicina Regenerativa', 'price' => 500000,
                'short' => 'Regeneración celular avanzada para rejuvenecimiento profundo.',
                'ai' => 'Los exosomas faciales ofrecen regeneración celular avanzada para un rejuvenecimiento profundo. Uso médico especializado; requiere valoración médica previa.'],
            ['name' => 'Exosomas Capilares', 'category' => 'Medicina Regenerativa', 'price' => 500000,
                'short' => 'Estimulan el crecimiento y mejoran la densidad capilar.',
                'ai' => 'Los exosomas capilares estimulan el crecimiento y mejoran la densidad capilar. Uso médico especializado; requiere valoración médica previa.'],

            // ── Láser / facial ─────────────────────────────────
            ['name' => 'Fotorejuvenecimiento (Láser diodo + IPL)', 'category' => 'Tecnología Médica', 'price' => 350000,
                'short' => 'Combina láser diodo e IPL para mejorar manchas, poros, textura y luminosidad. Desde $350.000.',
                'ai' => 'El fotorejuvenecimiento combina láser diodo y láser IPL para mejorar manchas, poros, textura y luminosidad de la piel. Requiere valoración médica. Sesiones desde $350.000.'],
            ['name' => 'Adipoestructuración Facial', 'category' => 'Medicina Estética', 'price' => 1000000,
                'short' => 'Redefine el rostro mediante el manejo de compartimentos grasos faciales. Incluye sueroterapia y 3 sesiones.',
                'ai' => 'Técnica avanzada para redefinir el rostro mediante el manejo estratégico de los compartimentos grasos faciales. Requiere valoración médica especializada. Valor de la sesión $1.000.000, incluye sueroterapia antiinflamatoria y de rejuvenecimiento, 3 sesiones y faja facial.'],
            ['name' => 'Endolifting Facial Premium', 'category' => 'Medicina Estética', 'price' => 1800000,
                'short' => 'Lifting facial sin cirugía: reafirma, eleva el rostro y estimula colágeno con efecto glow. Incluye drenajes, Tensamax, enzimas y mentonera.',
                'ai' => 'Endolifting facial premium: lifting sin cirugía que reafirma la piel y estimula colágeno. Mejora la flacidez, eleva el rostro y el perfil, rejuvenece con naturalidad y aporta efecto glow inmediato. Incluye drenajes, Tensamax, enzimas y mentonera. Valor $1.800.000. Primero se realiza una valoración médica personalizada ($150.000, que se descuenta del tratamiento).'],
            ['name' => 'Endolifting Corporal', 'category' => 'Medicina Estética', 'price' => null,
                'short' => 'Mejora flacidez y contorno corporal. Incluye drenajes linfáticos, Tensamax y enzimas. Valor según valoración.',
                'ai' => 'Endolifting corporal: procedimiento médico mínimamente invasivo que mejora la flacidez y el contorno corporal. Incluye drenajes linfáticos, Tensamax y enzimas. Requiere valoración previa; el valor se define bajo valoración médica.'],
            ['name' => 'Peeling Químico', 'category' => 'Medicina Estética', 'price' => 380000,
                'short' => 'Tratamiento médico para manchas, acné y envejecimiento cutáneo. Valor por sesión.',
                'ai' => 'El peeling químico es un tratamiento médico indicado para el manejo de manchas, acné y envejecimiento cutáneo. El tipo de peeling se define según valoración médica.'],
            ['name' => 'Hollywood Peel (Láser)', 'category' => 'Tecnología Médica', 'price' => 380000,
                'short' => 'Peeling con láser que mejora poros, textura y acné, con luminosidad inmediata. Renovación facial.',
                'ai' => 'El Hollywood Peel es un peeling con láser que mejora poros, textura de la piel y acné, aportando luminosidad inmediata. Ideal como tratamiento de renovación facial.'],

            // ── Medicina funcional / IV ────────────────────────
            ['name' => 'Medicina Funcional · Consulta', 'category' => 'Medicina Funcional', 'price' => 150000,
                'short' => 'Enfoque médico integral que trata la causa de los desequilibrios del organismo, no solo los síntomas.',
                'ai' => 'La medicina funcional es un enfoque médico integral que busca tratar la causa de los desequilibrios del organismo, no solo los síntomas. Incluye una evaluación completa del paciente. Consulta de medicina funcional $150.000.'],
            ['name' => 'Sueroterapia Funcional', 'category' => 'Medicina Funcional', 'price' => 200000,
                'short' => 'Aplicación IV de vitaminas, minerales y antioxidantes para energía, inmunidad y bienestar. 10% dto. desde 4 sueros.',
                'ai' => 'La sueroterapia funcional consiste en la aplicación intravenosa de vitaminas, minerales y antioxidantes para mejorar energía, inmunidad y bienestar general. Requiere valoración médica. Valor $200.000; descuento del 10% en paquetes de 4 sueros en adelante.'],
            ['name' => 'Hidrolipoclasia', 'category' => 'Medicina Estética', 'price' => null,
                'short' => 'Reducción de grasa localizada por infiltración controlada. Incluida en el paquete reductor de medidas.',
                'ai' => 'La hidrolipoclasia es un procedimiento médico para la reducción de grasa localizada mediante la infiltración controlada de soluciones específicas. Requiere valoración médica previa. Hace parte del paquete reductor de medidas (2 sesiones de hidrolipoclasia, 2 de ultrasonido y cavitación, 2 de drenaje linfático, 4 de mesoterapia reductora y reafirmante, 4 de vacuna antiobesidad y 4 de Tensamax; valor del paquete $1.400.000).'],
            ['name' => 'Valoración Médica', 'category' => 'Consulta Médica', 'price' => 75000,
                'short' => 'Consulta médica previa (presencial o virtual). Se descuenta del tratamiento.',
                'ai' => 'Consulta médica previa, presencial o virtual. Valor $75.000, que se descuenta del tratamiento.'],

            // ── Estética (gravada IVA) ─────────────────────────
            ['name' => 'Depilación Láser Diodo · Zona grande (6 sesiones)', 'category' => 'Estética', 'price' => 650000,
                'short' => 'Pierna completa, espalda o abdomen. 6 sesiones. Pago anticipado del paquete completo.',
                'ai' => 'Depilación láser diodo para zona grande (pierna completa, espalda, abdomen). Tecnología médica que reduce progresivamente el crecimiento del vello de forma segura, apta para diferentes tipos de piel. 6 sesiones, evaluación y ajuste médico incluidos. Condición: pago anticipado del valor total del paquete.'],
            ['name' => 'Depilación Láser Diodo · Zona mediana (6 sesiones)', 'category' => 'Estética', 'price' => 250000,
                'short' => 'Zona mediana. 6 sesiones. Pago anticipado del paquete completo.',
                'ai' => 'Depilación láser diodo para zona mediana. Reduce progresivamente el crecimiento del vello de forma segura, apta para diferentes tipos de piel. 6 sesiones, evaluación y ajuste médico incluidos. Pago anticipado del paquete.'],
            ['name' => 'Depilación Láser Diodo · Zona pequeña (6 sesiones)', 'category' => 'Estética', 'price' => 179000,
                'short' => 'Zona pequeña. 6 sesiones. Pago anticipado del paquete completo.',
                'ai' => 'Depilación láser diodo para zona pequeña. Reduce progresivamente el crecimiento del vello de forma segura, apta para diferentes tipos de piel. 6 sesiones, evaluación y ajuste médico incluidos. Pago anticipado del paquete.'],
            ['name' => 'Remoción de Tatuajes', 'category' => 'Estética', 'price' => 350000,
                'short' => 'Elimina pigmentos del tatuaje de forma progresiva con láser. Desde $350.000; sesiones según tamaño, color y profundidad.',
                'ai' => 'Tratamiento con tecnología láser para eliminar pigmentos de tatuajes de forma progresiva y segura. El número de sesiones depende del tamaño, color y profundidad del tatuaje. Sesiones desde $350.000; requiere valoración médica previa para definir valor final y número de sesiones.'],
            ['name' => 'SPA Médico Facial · Limpieza facial profunda', 'category' => 'Estética / Spa', 'price' => 180000,
                'short' => 'Limpieza facial profunda medicada para piel acneica, sensible o de mantenimiento. Mejora textura, poros y salud de la piel.',
                'ai' => 'Limpieza facial profunda medicada, indicada para piel acneica, sensible o como mantenimiento dermatológico. Ayuda a mejorar la textura, los poros y la salud de la piel. Valor $180.000.'],
        ];
    }
}
