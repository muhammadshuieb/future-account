type Props = {
  title: string
  description: string
  phase: string
}

export default function PlaceholderPage({ title, description, phase }: Props) {
  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-3xl font-bold">{title}</h1>
        <p className="mt-1 text-black/55">{description}</p>
      </header>
      <div className="rounded-2xl border border-dashed border-black/15 bg-white/70 p-8">
        <p className="text-sm font-medium text-teal">وحدة مخططة — {phase}</p>
        <p className="mt-3 max-w-xl text-sm leading-7 text-black/60">
          هذه الشاشة جاهزة للتنقل داخل النظام. سيتم تنفيذ منطق الأعمال والجداول التفصيلية
          في المرحلة المحددة بالخارطة الطريق في README.
        </p>
      </div>
    </div>
  )
}
