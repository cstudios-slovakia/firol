// Fills a .docx template with docxtemplater and writes the rendered .docx.
//
// Invoked by the PHP backend (Firol\Pdf\DocxRenderer) as a short-lived
// child process:
//
//     node render.mjs <template.docx> <data.json> <out.docx>
//
// docxtemplater options match the module spec (§7):
//   delimiters {{ }}  — the template authored by FIROL uses mustache tags
//   paragraphLoop     — clean removal/repetition of whole sections (incl.
//                        page breaks) when condition/loop tags sit in their
//                        own paragraph
//   linebreaks        — \n in a value becomes a real line break in Word
//
// Exit codes: 0 ok · 1 render/template error · 2 bad arguments.
import fs from 'node:fs';
import process from 'node:process';
import PizZip from 'pizzip';
import Docxtemplater from 'docxtemplater';

const [, , templatePath, dataPath, outPath] = process.argv;

if (!templatePath || !dataPath || !outPath) {
  process.stderr.write('Usage: render.mjs <template.docx> <data.json> <out.docx>\n');
  process.exit(2);
}

try {
  const content = fs.readFileSync(templatePath);
  const data = JSON.parse(fs.readFileSync(dataPath, 'utf8'));

  const zip = new PizZip(content);
  const doc = new Docxtemplater(zip, {
    delimiters: { start: '{{', end: '}}' },
    paragraphLoop: true,
    linebreaks: true,
  });

  doc.render(data);

  const buf = doc.getZip().generate({ type: 'nodebuffer', compression: 'DEFLATE' });
  fs.writeFileSync(outPath, buf);
  process.exit(0);
} catch (err) {
  // docxtemplater raises an aggregate error with a `.properties.errors`
  // array describing each bad tag — surface it so template problems are
  // diagnosable from the PHP error log.
  if (err && err.properties && Array.isArray(err.properties.errors)) {
    process.stderr.write(
      JSON.stringify(err.properties.errors.map((e) => e.properties)) + '\n',
    );
  }
  process.stderr.write((err && err.message ? err.message : String(err)) + '\n');
  process.exit(1);
}
