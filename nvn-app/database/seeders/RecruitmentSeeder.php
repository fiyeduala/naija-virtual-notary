<?php

namespace Database\Seeders;

use App\Enums\RecruitmentRole;
use App\Enums\VacancyState;
use App\Models\AssessmentTemplate;
use App\Models\Vacancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Four draft vacancies and the four exercises that go with them.
 *
 * Everything this seeder writes is a DRAFT. No vacancy it creates is visible
 * to anybody outside the panel, and publishing one is a deliberate act by an
 * administrator who has first supplied the facts below.
 *
 * What is deliberately left blank, and why:
 *
 *   employment type · which countries somebody may work from · the hours and
 *   their time zone · the closing date · whether pay is disclosed, and the
 *   figure if it is · headcount
 *
 * These are promises to a stranger about a job. Not one of them can be derived
 * from a codebase, and a plausible default here would reach a careers page as
 * a commitment nobody in the business made. So they are absent, every draft
 * therefore fails publicationBlockers(), and the vacancy screen lists exactly
 * what is missing. That is the intended handover, not an unfinished seeder.
 *
 * The descriptions and the exercises ARE written, because they are proposals
 * about the work rather than terms of employment, and a blank page is a worse
 * starting point than a draft somebody disagrees with.
 *
 * Idempotent, and non-destructive with it: everything is firstOrCreate on a
 * natural key, so a second run after somebody has edited a description changes
 * nothing. This seeder never updates or deletes an existing row.
 */
class RecruitmentSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [];

        foreach ($this->templates() as $roleKey => $definition) {
            $template = AssessmentTemplate::firstOrCreate(
                ['role_key' => $roleKey, 'name' => $definition['name']],
                $definition['attributes'] + ['version' => 1, 'is_active' => true],
            );

            $templates[$roleKey] = $template;

            $this->command?->line(($template->wasRecentlyCreated ? 'Created' : 'Kept')
                . ' exercise: ' . $definition['name']);
        }

        // Said once, applied to all four: what happens after Apply is pressed.
        // It is here rather than in each draft because it describes this
        // module's own process, which is the same whichever job it is, and a
        // sentence copied four times is a sentence that will drift three ways.
        $instructions = implode("\n", [
            'Apply below. You will be asked for your details, your CV and a few questions about the role.',
            'There is no fee to apply, at any stage, and there never will be. Nobody from Naija Virtual Notary will ask you to pay for an application, an assessment or an interview.',
            'If you are shortlisted we will email you a short written exercise, which takes about 30 to 45 minutes. You will have three working days to send it back.',
            'We do not ask for identity documents, bank details or a guarantor to apply. If a role needs any of that, it is asked for after an offer and not before.',
        ]);
        foreach ($this->vacancies() as $roleKey => $definition) {
            $vacancy = Vacancy::firstOrCreate(
                ['slug' => Str::slug($definition['title'])],
                $definition + [
                    'role_key'               => $roleKey,
                    'state'                  => VacancyState::Draft->value,
                    'department'             => RecruitmentRole::from($roleKey)->department(),
                    'assessment_template_id' => $templates[$roleKey]?->id,
                    'assessment_due_working_days' => 3,
                    'apply_instructions'     => $instructions,
                    'internal_notes'         => $this->handoverNote(),
                    'intro_video_enabled'    => false,
                    'intro_video_required'   => false,
                    'guarantors_required'    => false,

                    // Identity documents are asked for after an offer or not
                    // at all, and never on the application form. Off here so
                    // that turning it on is somebody's decision.
                    'identity_documents_required' => false,
                ],
            );

            $this->command?->line(($vacancy->wasRecentlyCreated ? 'Created' : 'Kept')
                . ' draft vacancy: ' . $definition['title']
                . ' (' . count($vacancy->publicationBlockers()) . ' things still to decide)');
        }
    }

    /**
     * The four exercises, one per role category.
     *
     * Rules every one of them keeps, from the specification:
     *
     *   - Fictional scenarios only. Not one task uses a real client, a real
     *     document, a real campaign or a real number. An exercise built on live
     *     work is unpaid labour wearing a hat.
     *   - Thirty to forty-five minutes of effort, stated on the page before
     *     anybody starts, so somebody with a job and a commute can decide
     *     honestly whether they have the evening.
     *   - Every one carries a rubric with written anchors, because a reviewer
     *     scoring "communication: 3" against nothing is recording a mood.
     *   - The brief supplies the facts about Naija Virtual Notary a candidate
     *     is allowed to rely on. Without it the exercise tests whether somebody
     *     can guess our turnaround time, which is not a skill.
     *
     * The scores inform a human decision and nothing else. Nothing in this
     * module adds them up and rejects anybody.
     *
     * @return array<string, array{name: string, attributes: array<string, mixed>}>
     */
    private function templates(): array
    {
        return [
            'customer_service'    => $this->customerService(),
            'marketing_executive' => $this->marketingExecutive(),
            'digital_marketing'   => $this->digitalMarketing(),
            'hr_manager'          => $this->hrManager(),
        ];
    }

    /**
     * The facts a candidate may rely on, shared by all four exercises.
     *
     * Its last paragraph is the point of it: an exercise that silently requires
     * a price or a refund rule is testing whether somebody will make one up,
     * and the people who do are the ones who will do it to a client.
     */
    private function brief(): string
    {
        return <<<'TEXT'
        Facts you can rely on while you answer. You are not expected to research us.

        Naija Virtual Notary is a Nigerian platform where a client uploads a document,
        meets a commissioned notary over video, and receives the notarized document
        back as a sealed PDF. Everything happens online. Clients are mostly Nigerians
        living abroad and Nigerian businesses sending documents overseas.

        A client pays before the session. A notary joins the call, checks the client's
        identity, witnesses the signature, and seals the document afterwards. The
        sealed file is then downloadable from the client's own dashboard.

        If a question below needs a fact we have not given you — a price, a turnaround
        time, a refund rule — say what you would need to check and who you would ask,
        then answer around it. Making a policy up is the wrong move, and noticing that
        you were about to is part of what we are looking at.
        TEXT;
    }

    /** @return array{name: string, attributes: array<string, mixed>} */
    private function customerService(): array
    {
        return [
            'name' => 'Customer Service — written exercise',
            'attributes' => [
                'brief'              => $this->brief(),
                'effort_minutes_min' => 30,
                'effort_minutes_max' => 45,
                'score_scale_max'    => 5,
                'tasks' => [
                    [
                        'key'   => 'anxious_client',
                        'label' => 'A client writes in: "I paid yesterday for an affidavit I need at my embassy appointment on Friday and nobody has contacted me. This is very disappointing." Write the reply you would send.',
                        'type'  => 'long_text',
                        'help'  => 'The client and the appointment are both invented. Write the email itself, not a description of what you would write.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'saying_no',
                        'label' => 'The same client then asks you to date the document last month, because that is the date on the form they already submitted. Write that reply too.',
                        'type'  => 'long_text',
                        'help'  => 'We are looking at how you refuse something you cannot do without losing the person.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'escalate',
                        'label' => 'Of those two messages, which would you pass to a colleague rather than answer yourself, and what exactly would you tell them?',
                        'type'  => 'long_text',
                        'help'  => 'Two or three sentences is plenty.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'hours',
                        'label' => 'A client in London writes at 02:00 Lagos time. What happens next, in your version of this job?',
                        'type'  => 'long_text',
                        'help'  => 'There is no right answer. We want to know what you would propose.',
                        'required' => false,
                    ],
                ],
                'rubric' => [
                    [
                        'key'     => 'clarity',
                        'label'   => 'Clear, plain writing',
                        'anchors' => [
                            '1' => 'Hard to follow, or so formal the meaning is buried.',
                            '3' => 'Understandable, with some padding or jargon.',
                            '5' => 'Short sentences, no filler, nothing the client must read twice.',
                        ],
                    ],
                    [
                        'key'     => 'ownership',
                        'label'   => 'Takes responsibility without over-promising',
                        'anchors' => [
                            '1' => 'Blames the client or the system, or promises a date nobody can keep.',
                            '3' => 'Apologises and commits to something vague.',
                            '5' => 'Says what will happen, by when, and who is doing it — and nothing beyond that.',
                        ],
                    ],
                    [
                        'key'     => 'integrity',
                        'label'   => 'Refuses the backdating cleanly',
                        'anchors' => [
                            '1' => 'Agrees, hedges, or offers to "see what I can do".',
                            '3' => 'Refuses, but leaves the client with nothing.',
                            '5' => 'Refuses plainly, explains why it protects the client, offers the next best thing.',
                        ],
                    ],
                    [
                        'key'     => 'judgement',
                        'label'   => 'Knows what is not theirs to decide',
                        'anchors' => [
                            '1' => 'Invents a policy, or escalates everything.',
                            '3' => 'Escalates the right one, but hands over little context.',
                            '5' => 'Escalates the right one with exactly what a colleague needs to act.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array{name: string, attributes: array<string, mixed>} */
    private function marketingExecutive(): array
    {
        return [
            'name' => 'Marketing Executive — written exercise',
            'attributes' => [
                'brief'              => $this->brief(),
                'effort_minutes_min' => 30,
                'effort_minutes_max' => 45,
                'score_scale_max'    => 5,
                'tasks' => [
                    [
                        'key'   => 'segment',
                        'label' => 'Pick one group of people who need documents notarized and would not currently think of us. Say who they are, where they already are, and what would make them try us once.',
                        'type'  => 'long_text',
                        'help'  => 'One group, chosen on purpose. A list of six is easier to write and tells us less.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'pitch_email',
                        'label' => 'Write the first email you would send to a fictional partner body — say, the admissions office of a university whose graduates need transcripts notarized for visa applications. Subject line included.',
                        'type'  => 'long_text',
                        'help'  => 'Invent the body and the contact. Do not write to a real organisation for this exercise.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'objection',
                        'label' => 'They reply: "Our applicants already use a notary down the road and he charges less." What do you say?',
                        'type'  => 'long_text',
                        'help'  => 'You do not know our prices and are not expected to invent one.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'measure',
                        'label' => 'A month after that email went out, what single number would tell you whether this was worth doing, and where would you expect to find it?',
                        'type'  => 'short_text',
                        'help'  => 'One number, named.',
                        'required' => true,
                    ],
                ],
                'rubric' => [
                    [
                        'key'     => 'specificity',
                        'label'   => 'Chooses something specific and says why',
                        'anchors' => [
                            '1' => 'Generic audiences, generic channels: "social media users", "everybody who needs documents".',
                            '3' => 'A real group, but the reasoning is thin.',
                            '5' => 'A group you can picture, named where they actually are, with a reason they would move.',
                        ],
                    ],
                    [
                        'key'     => 'writing',
                        'label'   => 'The email is one somebody would answer',
                        'anchors' => [
                            '1' => 'Mass-mail tone, no clear ask, or three paragraphs about us.',
                            '3' => 'Polite and clear, but forgettable.',
                            '5' => 'Opens with their problem, one clear ask, short enough to read on a phone.',
                        ],
                    ],
                    [
                        'key'     => 'honesty',
                        'label'   => 'Handles the price objection without inventing anything',
                        'anchors' => [
                            '1' => 'Quotes a figure, promises a discount, or claims a saving nobody has calculated.',
                            '3' => 'Avoids the question.',
                            '5' => 'Moves the conversation to what the client actually gets, and says what they would check before answering on price.',
                        ],
                    ],
                    [
                        'key'     => 'measurement',
                        'label'   => 'Names a number that could actually be read',
                        'anchors' => [
                            '1' => 'Impressions, reach, or "engagement".',
                            '3' => 'A sensible number with no idea where it comes from.',
                            '5' => 'A number tied to a completed job or a reply, and a plausible place to find it.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array{name: string, attributes: array<string, mixed>} */
    private function digitalMarketing(): array
    {
        return [
            'name' => 'Digital Marketing — written exercise',
            'attributes' => [
                'brief'              => $this->brief(),
                'effort_minutes_min' => 30,
                'effort_minutes_max' => 45,
                'score_scale_max'    => 5,
                'tasks' => [
                    [
                        'key'   => 'ad_copy',
                        'label' => 'Write two versions of a single advert aimed at Nigerians abroad who need a document notarized. Say in one line what each version is betting on.',
                        'type'  => 'long_text',
                        'help'  => 'Two versions, not six. The interesting part is the difference between them.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'funnel',
                        'label' => 'A fictional campaign: 10,000 people saw the advert, 400 clicked, 120 started an application, 11 paid. Where is the problem, and what would you change first?',
                        'type'  => 'long_text',
                        'help'  => 'These numbers are made up for the exercise. Explain how you read them.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'test',
                        'label' => 'Describe the first test you would run on that, including how you would know it had finished.',
                        'type'  => 'long_text',
                        'help'  => 'We are looking for one test you could actually finish, not a quarterly plan.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'tools',
                        'label' => 'Which tools have you personally run ads or analytics in? Name them plainly.',
                        'type'  => 'short_text',
                        'help'  => 'A plain list. Nothing is checked against a required set.',
                        'required' => false,
                    ],
                ],
                'rubric' => [
                    [
                        'key'     => 'copy',
                        'label'   => 'The copy speaks to a person',
                        'anchors' => [
                            '1' => 'Feature list, or the word "seamless".',
                            '3' => 'Clear, generic, could be any service.',
                            '5' => 'Names the situation somebody is actually in, in their words.',
                        ],
                    ],
                    [
                        'key'     => 'diagnosis',
                        'label'   => 'Reads the numbers correctly',
                        'anchors' => [
                            '1' => 'Picks the wrong step, or treats all four drops as equally interesting.',
                            '3' => 'Finds a real drop and stops there.',
                            '5' => 'Identifies the steepest drop, says what could plausibly cause it, and distinguishes what the numbers show from what they only suggest.',
                        ],
                    ],
                    [
                        'key'     => 'method',
                        'label'   => 'The test could be finished',
                        'anchors' => [
                            '1' => 'Change everything at once, or no way of telling whether it worked.',
                            '3' => 'One change, vague finish line.',
                            '5' => 'One change, one measure, a stated point at which it is called either way.',
                        ],
                    ],
                    [
                        'key'     => 'restraint',
                        'label'   => 'Does not invent data or spend',
                        'anchors' => [
                            '1' => 'Quotes a budget, a cost per acquisition, or a benchmark nobody supplied.',
                            '3' => 'Mostly sticks to what was given.',
                            '5' => 'Works only from the figures given and says plainly what else would need measuring.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array{name: string, attributes: array<string, mixed>} */
    private function hrManager(): array
    {
        return [
            'name' => 'Human Resources Manager — written exercise',
            'attributes' => [
                'brief'              => $this->brief(),
                'effort_minutes_min' => 30,
                'effort_minutes_max' => 45,
                'score_scale_max'    => 5,
                'tasks' => [
                    [
                        'key'   => 'onboarding',
                        'label' => 'A new customer-service hire starts on Monday and works from home in another city. List what has to happen in their first week, in order.',
                        'type'  => 'long_text',
                        'help'  => 'Fully remote. Assume nobody will meet them in person.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'grievance',
                        'label' => 'A fictional situation: a team member tells you privately that a colleague keeps taking credit for their work in team calls, and asks you not to say anything. What do you do, in what order?',
                        'type'  => 'long_text',
                        'help'  => 'Invented people. We are interested in the sequence and what you would and would not promise.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'records',
                        'label' => 'Name three pieces of information about an employee that you would store, and three you would refuse to collect. One line each on why.',
                        'type'  => 'long_text',
                        'help'  => 'Short lines.',
                        'required' => true,
                    ],
                    [
                        'key'   => 'hiring_step',
                        'label' => 'If you could change one thing about the application process you have just gone through, what would it be?',
                        'type'  => 'long_text',
                        'help'  => 'Answer candidly. This is read, and a blunt answer has never counted against anybody here.',
                        'required' => false,
                    ],
                ],
                'rubric' => [
                    [
                        'key'     => 'practicality',
                        'label'   => 'The first week would actually work',
                        'anchors' => [
                            '1' => 'A policy list, or steps that assume an office.',
                            '3' => 'Sensible but generic.',
                            '5' => 'Ordered, remote-aware, and somebody would know on Friday whether it had gone well.',
                        ],
                    ],
                    [
                        'key'     => 'confidence',
                        'label'   => 'Handles confidence honestly',
                        'anchors' => [
                            '1' => 'Promises absolute secrecy, or goes straight to the colleague.',
                            '3' => 'Listens, then is vague about what happens next.',
                            '5' => 'Is clear about what can and cannot be kept private before being told more, and says why.',
                        ],
                    ],
                    [
                        'key'     => 'data_judgement',
                        'label'   => 'Knows what not to collect',
                        'anchors' => [
                            '1' => 'Would collect anything asked for, including religion, marital status or a photograph, without comment.',
                            '3' => 'Draws a line but cannot say where it comes from.',
                            '5' => 'Draws a clear line, ties each refusal to a reason, and distinguishes what is needed to pay somebody from what is merely interesting.',
                        ],
                    ],
                    [
                        'key'     => 'process',
                        'label'   => 'Thinks in processes rather than incidents',
                        'anchors' => [
                            '1' => 'Handles each case from scratch.',
                            '3' => 'Mentions documenting things.',
                            '5' => 'Describes something repeatable that a second person could follow.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The note left on every draft, listing what only the business can supply.
     *
     * It is repeated on each vacancy rather than written once somewhere central
     * because this is the text somebody reads while editing that vacancy, and a
     * note filed elsewhere is a note nobody reads.
     */
    private function handoverNote(): string
    {
        return <<<'TEXT'
        Seeded draft. The description below is a proposal — rewrite it freely.

        Before this can be published, the vacancy screen will require:

          · the employment type (full time, part time, contract — this is your call)
          · which countries somebody may work from
          · the hours they are expected to be available, and the time zone
          · the closing date
          · whether pay is disclosed on the advert, and the figures if it is

        None of those were filled in, deliberately. Each one is a promise to a
        stranger about a job, and nothing in this codebase knows the answer. A
        plausible-looking default here would have reached a public careers page
        as a commitment nobody in the business made.

        Also decide, per role: whether an introduction video is offered at all
        (off), whether identity documents are requested after an offer (off),
        and whether a guarantor is required (off, and it should stay off unless
        there is a reason you can write down).
        TEXT;
    }

    /**
     * The four drafts.
     *
     * Responsibilities and requirements are proposals about the work. Salary,
     * headcount, contract type and reporting lines are absent, because those
     * are facts about your business rather than facts about the role, and the
     * specification was explicit that they must not be invented.
     *
     * @return array<string, array<string, mixed>>
     */
    private function vacancies(): array
    {
        return [
            'customer_service' => [
                'title'   => 'Customer Service Representative (Remote)',
                'summary' => 'Answer the people using Naija Virtual Notary — mostly Nigerians abroad with a '
                    . 'deadline and a document — and make sure nobody is left wondering what is happening to theirs.',
                'responsibilities' => <<<'TEXT'
                Answer client messages by email and chat, in plain language.
                Walk clients through uploading a document and joining a video session.
                Keep an eye on sessions that have not happened yet and chase the ones that are drifting.
                Tell a client clearly when something cannot be done, and what can be done instead.
                Pass anything legal, financial or disputed to the right colleague, with the full context.
                Keep notes on each case so the next person picking it up is not starting from nothing.
                TEXT,
                'requirements' => <<<'TEXT'
                You can write clear English quickly, without sounding like a form letter.
                You have handled unhappy people before and did not take it personally.
                You are comfortable working on your own for most of the day.
                You can tell the difference between a question you should answer and one you should pass on.
                Reliable electricity, a working computer and an internet connection you can hold a video call on.
                Formal qualifications are not a filter here. Tell us what you have done.
                TEXT,
                'remote_arrangements' => <<<'TEXT'
                This role is fully remote. There is no office to come to.

                You will need your own computer and internet connection, and a plan for when the power
                goes — an inverter, a generator, a backup data plan, or somewhere else you can work from.
                Tell us in the application what yours is.
                TEXT,
                'screening_questions' => [
                    ['key' => 'written_english', 'label' => 'Describe the hardest message you have ever had to send to a customer, and what you said.', 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['key' => 'support_experience', 'label' => 'Have you been paid to answer customers before?', 'type' => 'select', 'required' => true, 'options' => ['No, this would be my first', 'Less than a year', 'One to three years', 'More than three years']],
                    ['key' => 'power_backup', 'label' => 'What is your backup when the power goes out during a shift?', 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['key' => 'video_calls', 'label' => 'Can you hold a video call on your connection without it dropping?', 'type' => 'yes_no', 'required' => true, 'options' => []],
                ],
            ],
            'marketing_executive' => [
                'title'   => 'Marketing Executive (Remote)',
                'summary' => 'Find the people and organisations who already tell Nigerians "you need this notarized", '
                    . 'and give them a reason to say our name when they do.',
                'responsibilities' => <<<'TEXT'
                Find and approach organisations whose people routinely need documents notarized — schools, agencies, employers, professional bodies.
                Open those conversations yourself, by email, phone and in writing.
                Explain what the platform does to somebody who has never used it, without overstating it.
                Keep a record of who was approached, what was said, and what came of it.
                Feed back what you keep hearing, including the objections, so the service can answer them.
                Work with whoever is handling digital marketing rather than around them.
                TEXT,
                'requirements' => <<<'TEXT'
                You have approached organisations cold before and can describe how it went.
                You can write a short email that gets answered.
                You keep your own records without being chased for them.
                You are comfortable being told no, repeatedly, and asking again later.
                You can explain a service accurately, including its limits.
                Reliable electricity, a working computer and an internet connection you can hold a video call on.
                TEXT,
                'remote_arrangements' => <<<'TEXT'
                This role is fully remote. There is no office to come to.

                Some of the work is conversations with organisations, which may mean calls during their
                working hours rather than yours. You will need your own computer and internet connection,
                and a plan for when the power goes.
                TEXT,
                'screening_questions' => [
                    ['key' => 'outreach_example', 'label' => 'Describe one organisation or client you brought in yourself. What did you actually do?', 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['key' => 'experience_length', 'label' => 'How long have you been doing business development or marketing work?', 'type' => 'select', 'required' => true, 'options' => ['Under a year', 'One to three years', 'Three to five years', 'More than five years']],
                    ['key' => 'portfolio', 'label' => 'A link to something you have written or sent — a pitch, a deck, a campaign. Optional.', 'type' => 'url', 'required' => false, 'options' => []],
                    ['key' => 'power_backup', 'label' => 'What is your backup when the power goes out during the working day?', 'type' => 'long_text', 'required' => true, 'options' => []],
                ],
            ],
            'digital_marketing' => [
                'title'   => 'Digital Marketing Specialist (Remote)',
                'summary' => 'Run the adverts, the search presence and the social accounts, and be able to say which of '
                    . 'them actually produced a notarized document rather than a click.',
                'responsibilities' => <<<'TEXT'
                Plan and run paid adverts, and say plainly what each one is meant to achieve.
                Write and schedule social posts, and reply to what comes back.
                Improve how the site reads to search engines, and to people.
                Report on what happened: what was spent, what came of it, and what you would change.
                Set up and sanity-check tracking, so a number on a report means what it says.
                Run small tests and finish them, rather than changing five things at once.
                TEXT,
                'requirements' => <<<'TEXT'
                You have run paid adverts with real money behind them and can talk through the results.
                You are comfortable in an analytics dashboard and can tell a real change from noise.
                You can write ad and social copy that sounds like a person.
                You understand that a campaign which produced attention and no customers did not work.
                You know where tracking goes wrong, and are honest when a number cannot be trusted.
                Reliable electricity, a working computer and an internet connection.
                TEXT,
                'remote_arrangements' => <<<'TEXT'
                This role is fully remote. There is no office to come to.

                You will need your own computer and internet connection, and a plan for when the power
                goes. Access to any advertising or analytics account is granted to you individually and
                is never shared between people.
                TEXT,
                'screening_questions' => [
                    ['key' => 'campaign_example', 'label' => 'Describe one campaign you ran. What was the budget, what did you measure, and what happened?', 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['key' => 'platforms', 'label' => 'Which advertising platforms have you personally run spend on?', 'type' => 'short_text', 'required' => true, 'options' => []],
                    ['key' => 'budget_managed', 'label' => 'Roughly the largest monthly budget you have been responsible for.', 'type' => 'short_text', 'required' => false, 'options' => []],
                    ['key' => 'work_sample', 'label' => 'A link to a campaign, a report or an account you worked on. Optional.', 'type' => 'url', 'required' => false, 'options' => []],
                ],
            ],
            'hr_manager' => [
                'title'   => 'Human Resources Manager (Remote)',
                'summary' => 'Look after the people doing the work: how they are hired, how they start, how they are '
                    . 'paid attention to, and what is written down about them.',
                'responsibilities' => <<<'TEXT'
                Run hiring end to end: the advert, the shortlist, the interviews, the decision.
                Get new people started properly when nobody will meet them in person.
                Keep employee records accurate, current and seen only by the people who need them.
                Handle grievances and disagreements fairly, and write down what was decided and why.
                Keep track of who is available when, across a team that is not in one place.
                Write the handful of policies this business actually needs, and no more.
                TEXT,
                'requirements' => <<<'TEXT'
                You have hired people before and can describe a decision you got wrong.
                You can hold a difficult conversation without flinching or inflaming it.
                You are careful with other people's information, by instinct rather than by rule.
                You can write a policy somebody would actually read.
                You understand Nigerian employment practice well enough to know when to take advice.
                Reliable electricity, a working computer and an internet connection you can hold a video call on.
                TEXT,
                'remote_arrangements' => <<<'TEXT'
                This role is fully remote. There is no office to come to.

                Much of it is conversations, so you will need somewhere private to take a call — an HR
                conversation overheard is an HR problem. You will need your own computer and internet
                connection, and a plan for when the power goes.
                TEXT,
                'screening_questions' => [
                    ['key' => 'hiring_experience', 'label' => 'How many people have you hired, and for what kinds of role?', 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['key' => 'hr_years', 'label' => 'How long have you worked in HR or people management?', 'type' => 'select', 'required' => true, 'options' => ['Under two years', 'Two to five years', 'Five to ten years', 'More than ten years']],
                    ['key' => 'remote_team', 'label' => 'Have you managed people you have never met in person?', 'type' => 'yes_no', 'required' => true, 'options' => []],
                    ['key' => 'private_space', 'label' => 'Do you have somewhere private to hold a confidential call?', 'type' => 'yes_no', 'required' => true, 'options' => []],
                ],
            ],
        ];
    }
}
