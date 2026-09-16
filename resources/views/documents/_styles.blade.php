{{-- Print styles shared by the CV and cover letter: black text in the bundled Inter, with no external assets. --}}
<style>
{!! \App\Pdf\DocumentFormat::interFontFaces() !!}

* { box-sizing: border-box; margin: 0; padding: 0; }
html { font-family: 'Inter', sans-serif; font-size: 10pt; line-height: 1.45; color: #000; background: #fff; }
h1 { font-size: 20pt; font-weight: 700; line-height: 1.2; letter-spacing: -0.01em; }
.contact { margin-top: 3pt; }
</style>
