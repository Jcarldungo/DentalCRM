import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageContainer, PageHeader } from '@/Components/UI/Page';
import { formatPeso } from '@/Pages/Patients/format';
import RangePicker from './RangePicker';
import { TrendChart, MiniBars } from './charts';
import { Card, NoShowList, ProviderTable, RateBar, Section, StatTile } from './components';

function pct(n) {
    return `${Math.round(n * 1000) / 10}%`;
}

export default function Index({ meta, revenue, appointments, patients }) {
    return (
        <AuthenticatedLayout title="Reports">
            <Head title="Reports" />

            <PageContainer>
                <PageHeader title="Reports" description={meta.label} />

                <RangePicker meta={meta} />

                <div className="space-y-10">
                    <Section title="Revenue">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <StatTile label="Collected" value={formatPeso(revenue.collected_total)} sub="payments received" />
                            <StatTile label="Invoiced" value={formatPeso(revenue.invoiced_total)} sub="net of discount" />
                            <StatTile
                                label="Outstanding"
                                value={formatPeso(revenue.outstanding.total)}
                                sub={`${revenue.outstanding.count} open invoice${revenue.outstanding.count === 1 ? '' : 's'} · as of ${revenue.outstanding.as_of}`}
                            />
                        </div>

                        <Card title="Collected over time">
                            <TrendChart
                                series={revenue.collected_trend.series}
                                bucket={revenue.collected_trend.bucket}
                                valueFormat="peso"
                            />
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2">
                            <Card title="By provider" note="invoiced, gross of discount">
                                <MiniBars rows={revenue.by_provider} valueFormat="peso" />
                            </Card>
                            <Card title="By treatment" note="invoiced">
                                <MiniBars rows={revenue.by_treatment} valueFormat="peso" />
                            </Card>
                        </div>

                        <Card title="Payment method mix">
                            <MiniBars
                                rows={revenue.method_mix.map((m) => ({
                                    label: m.label.replace('_', ' '),
                                    value: m.value,
                                    sub: `${m.count} payment${m.count === 1 ? '' : 's'}`,
                                }))}
                                valueFormat="peso"
                            />
                        </Card>
                    </Section>

                    <Section title="Appointments">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile label="Total" value={appointments.total} />
                            <StatTile label="Completion" value={pct(appointments.rates.completion)} />
                            <StatTile label="Cancellation" value={pct(appointments.rates.cancellation)} />
                            <StatTile label="No-show" value={pct(appointments.rates.no_show)} />
                        </div>

                        <Card title="Volume over time">
                            <TrendChart
                                series={appointments.volume_trend.series}
                                bucket={appointments.volume_trend.bucket}
                                valueFormat="count"
                            />
                        </Card>

                        <div className="grid gap-4 md:grid-cols-2">
                            <Card title="By provider">
                                <ProviderTable rows={appointments.by_provider} />
                            </Card>
                            <Card title="By type">
                                <MiniBars
                                    rows={appointments.by_type.map((t) => ({ label: t.label, value: t.value }))}
                                    valueFormat="count"
                                />
                            </Card>
                        </div>

                        <Card title="Rates">
                            <div className="space-y-3">
                                <RateBar label="Completed" value={appointments.rates.completion} />
                                <RateBar label="Cancelled / declined" value={appointments.rates.cancellation} />
                                <RateBar label="No-show" value={appointments.rates.no_show} />
                            </div>
                        </Card>
                    </Section>

                    <Section title="Patients">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile label="New patients" value={patients.new_total} />
                            <StatTile label="Returning" value={patients.seen.returning} />
                            <StatTile label="First visit" value={patients.seen.first_visit} />
                            <StatTile label="No-show patients" value={patients.no_show_patients.count} />
                        </div>

                        <Card title="New patients over time">
                            <TrendChart
                                series={patients.new_trend.series}
                                bucket={patients.new_trend.bucket}
                                valueFormat="count"
                            />
                        </Card>

                        <Card title="No-show patients">
                            <NoShowList list={patients.no_show_patients.list} />
                        </Card>
                    </Section>
                </div>
            </PageContainer>
        </AuthenticatedLayout>
    );
}
