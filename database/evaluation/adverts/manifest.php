<?php

/**
 * The test set for the AI extraction prompt: real Zambian adverts (from Job Search Zambia, page navigation removed, emails
 * removed) and the answer a careful person would give for each, in the Phase 3 schema.
 *
 * Scored sections: qualifications, professional_registrations, experience_requirements. Skills and other requirements are
 * not part of the first test set. The answers are DRAFTS written from the adverts for the owner to check; the hard ones
 * say why they are hard in `hard`.
 *
 * `split`: "tune" adverts may be read while the prompt is being tuned; "holdout" ones are scored once, at the end.
 * Two adverts name several positions (`multi_position`): the schema cannot say which position a qualification belongs to,
 * so their answer is everything asked for across positions and they are a known limitation, not a score.
 * Either-or groups (alternative_group) are only used inside one section, so "ACCA or a degree" cannot be tied across sections.
 */

$q = fn (string $name, ?string $level, ?string $field, string $type, string $quote, ?int $group = null) => [
    'name' => $name, 'level' => $level, 'field' => $field, 'requirement_type' => $type, 'alternative_group' => $group, 'original_text' => $quote, 'confidence' => 1.0,
];
$r = fn (string $name, string $type, string $quote, ?int $group = null) => [
    'name' => $name, 'requirement_type' => $type, 'alternative_group' => $group, 'original_text' => $quote, 'confidence' => 1.0,
];
$e = fn (int $min, ?int $max, ?string $area, string $type, string $quote) => [
    'years_min' => $min, 'years_max' => $max, 'area' => $area, 'requirement_type' => $type, 'alternative_group' => null, 'original_text' => $quote, 'confidence' => 1.0,
];
$ad = fn (string $file, string $title, string $sector, string $split, array $expected, array $hard = [], bool $multi = false) => [
    'file' => $file, 'title' => $title, 'sector' => $sector, 'split' => $split, 'multi_position' => $multi, 'hard' => $hard,
    'expected' => ['qualification_found' => ! empty($expected['qualifications'])] + array_replace(['qualifications' => [], 'professional_registrations' => [], 'experience_requirements' => []], $expected),
];

return [
    $ad('01-occupational-health-nurse.txt', 'Occupational Health Nurse', 'healthcare', 'tune', [
        'qualifications' => [
            $q('Diploma in Nursing', 'diploma', 'nursing', 'mandatory', 'Must have a current RN qualification (essential) following completion of Diploma in Nursing (essential)'),
            $q('Certification in occupational health nursing', 'certificate', 'occupational health nursing', 'advantage', 'Training in occupational health nursing with certification would be an asset.'),
            $q('Certification in Audiometry, Spirometry and Vision testing', 'certificate', 'audiometry, spirometry and vision testing', 'advantage', 'Training in Audiometry, Spirometry and Vision testing with appropriate certification will be an asset'),
        ],
        'professional_registrations' => [$r('practising licence from the NMCZ', 'mandatory', 'Must have a current practising licence from the NMCZ.')],
        'experience_requirements' => [$e(2, 3, null, 'mandatory', '2 to 3 years experience')],
    ], ['qualification and certification given as "training with certification" (asset)', 'bare line "2 to 3 years experience" with no verb', '"RN qualification" is part of the diploma line']),

    $ad('02-enumerators.txt', 'Enumerators', 'healthcare', 'tune', [
        'qualifications' => [
            $q('Diploma in demography, statistics, social sciences, public health, monitoring and evaluation, development studies or a related field', 'diploma', null, 'mandatory', 'Diploma or degree in demography, statistics, social sciences, public health, monitoring and evaluation, development studies or a related field.', 1),
            $q('Degree in demography, statistics, social sciences, public health, monitoring and evaluation, development studies or a related field', 'bachelor', null, 'mandatory', 'Diploma or degree in demography, statistics, social sciences, public health, monitoring and evaluation, development studies or a related field.', 1),
        ],
    ], ['"diploma or degree": two levels, one choice', 'a list of subjects in one sentence', 'experience stated without a number of years']),

    $ad('03-registered-nurses-midwives.txt', 'Registered Nurses & Midwives', 'healthcare', 'holdout', [
        'professional_registrations' => [$r('Valid practising licence', 'mandatory', 'Valid practising licence')],
        'experience_requirements' => [$e(5, null, 'relevant working experience', 'mandatory', 'Minimum Experience: 5 years of relevant working experience.')],
    ], ['NO qualification is named: the model must not invent a diploma or NMCZ', '"academic and professional qualifications" is a document to submit, not a requirement']),

    $ad('04-program-assistant.txt', 'Program Assistant', 'ngo', 'tune', [
        'qualifications' => [$q('Bachelor’s degree in development studies, Public Health, Social Sciences, Community Development, Project Management, or related fields', 'bachelor', null, 'mandatory', 'Bachelor’s degree in development studies, Public Health, Social Sciences, Community Development, Project Management, or related fields.')],
        'experience_requirements' => [$e(1, null, 'supporting the implementation of NGO or community-based development projects', 'mandatory', 'At least one year of relevant experience supporting the implementation of NGO or community-based development projects.')],
    ], ['number of years written as a word']),

    $ad('05-lab-technologists.txt', 'Lab Technologists – PCR Laboratory', 'healthcare', 'holdout', [
        'qualifications' => [
            $q('Diploma in Biomedical Sciences', 'diploma', 'biomedical sciences', 'mandatory', 'A Diploma in Biomedical Sciences with at least three (3) years’ relevant work experience.'),
            $q('Bachelor’s degree in Biomedical Sciences', 'bachelor', 'biomedical sciences', 'advantage', 'A Bachelor’s degree in Biomedical Sciences or a related field will be an added advantage.'),
            $q('Training in SLMTA/SLIPTA', null, null, 'advantage', 'Training in SLMTA/SLIPTA will be an added advantage.'),
            $q('Training in Biosafety and Biosecurity and/or National Rapid HIV Testing', null, null, 'advantage', 'Training in Biosafety and Biosecurity and/or National Rapid HIV Testing will be an added advantage.'),
        ],
        'professional_registrations' => [$r('Health Professions Council of Zambia (HPCZ)', 'mandatory', 'A valid practicing license with the Health Professions Council of Zambia (HPCZ).')],
        'experience_requirements' => [
            $e(3, null, null, 'mandatory', 'at least three (3) years’ relevant work experience'),
            $e(2, null, 'a PCR/molecular laboratory', 'mandatory', 'At least two (2) years’ relevant laboratory experience in a PCR/molecular laboratory.'),
        ],
    ], ['mandatory diploma and "added advantage" degree in the same paragraph', 'several advantage-level trainings']),

    $ad('06-accounts-semi-seniors.txt', 'Accounts Semi-Seniors (x2)', 'finance', 'tune', [
        'professional_registrations' => [$r('ZICA Licentiate Member', 'mandatory', 'Education and Professional Status: ZICA Licentiate Member.')],
        'experience_requirements' => [$e(3, null, 'relevant work experience', 'mandatory', 'Minimum of 3 years of relevant work experience.')],
    ], ['a professional membership under the heading "Education", and no academic qualification at all']),

    $ad('07-assistant-accountant-receivables.txt', 'Assistant Accountant Receivables – OMC', 'finance', 'holdout', [
        'qualifications' => [$q('Degree in Accounting or Finance', 'bachelor', 'accounting or finance', 'mandatory', 'Degree in Accounting or Finance')],
        'professional_registrations' => [
            $r('CA Zambia', 'mandatory', 'Finalist of CA Zambia, ACCA, CIMA OR', 1),
            $r('ACCA', 'mandatory', 'Finalist of CA Zambia, ACCA, CIMA OR', 1),
            $r('CIMA', 'mandatory', 'Finalist of CA Zambia, ACCA, CIMA OR', 1),
            $r('ZICA', 'mandatory', 'Must be a member of ZICA'),
        ],
        'experience_requirements' => [$e(3, null, 'Accounts Receivables', 'mandatory', 'Must have a minimum of 3+ years’ experience in Accounts Receivables')],
    ], ['"Finalist of CA Zambia, ACCA, CIMA OR a degree": a choice that crosses the registration and qualification lists', 'a "plus" line that is not a requirement']),

    $ad('08-multiple-positions.txt', 'Multiple Positions (Nava Avocado)', 'agriculture', 'tune', [
        'qualifications' => [$q('Relevant tertiary qualification', null, null, 'mandatory', 'Relevant tertiary qualification and minimum 15 years’ experience in avocado/fresh-produce marketing, exports and commercial operations.')],
        'professional_registrations' => [
            $r('Remote Pilot Licence/Certificate', 'mandatory', 'Must hold a recognised Remote Pilot Licence/Certificate and have agricultural drone experience in spraying, mapping and multispectral monitoring.'),
            $r('Chartered Accountant', 'mandatory', 'Qualified Chartered Accountant with minimum 10 years’ progressive finance, accounting, audit and commercial experience, including large-scale agriculture/agribusiness.'),
        ],
        'experience_requirements' => [
            $e(15, null, 'commercial fruit-production', 'mandatory', 'Minimum 15 years’ commercial fruit-production experience, including avocado, with at least 3 years managing avocado production at senior level.'),
            $e(15, null, 'avocado/fresh-produce marketing, exports and commercial operations', 'mandatory', 'Relevant tertiary qualification and minimum 15 years’ experience in avocado/fresh-produce marketing, exports and commercial operations.'),
            $e(10, null, 'finance, accounting, audit and commercial', 'mandatory', 'Qualified Chartered Accountant with minimum 10 years’ progressive finance, accounting, audit and commercial experience, including large-scale agriculture/agribusiness.'),
        ],
    ], ['THREE positions in one advert: the schema cannot say which qualification belongs to which', 'a licence and a "Qualified Chartered Accountant"'], true),

    $ad('09-assistant-accountant.txt', 'Assistant Accountant', 'finance', 'tune', [
        'qualifications' => [$q('Degree in Accounts', 'bachelor', 'accounts', 'mandatory', 'Degree in Accounts or Equivalent')],
    ], ['"or Equivalent" must not turn into a second qualification', '"Relevant work experience is preferred" has no number of years']),

    $ad('10-analyst-budgeting.txt', 'Analyst – Budgeting', 'finance', 'tune', [
        'qualifications' => [$q('Degree in Accounting or Finance', 'bachelor', 'accounting or finance', 'mandatory', 'Degree in Accounting or Finance.')],
        'professional_registrations' => [
            $r('ACCA', 'advantage', 'A professional qualification such as ACCA or CIMA will be an advantage.', 1),
            $r('CIMA', 'advantage', 'A professional qualification such as ACCA or CIMA will be an advantage.', 1),
        ],
        'experience_requirements' => [$e(3, null, 'a finance role in an NGO environment', 'mandatory', 'Minimum of 3 years relevant experience in a finance role in an NGO environment.')],
    ], ['ACCA or CIMA is an ADVANTAGE, not a requirement', '"With 75 years of experience" is the employer\'s history, not a requirement']),

    $ad('11-hr-admin-assistant.txt', 'HR and Administrative Assistant', 'administration', 'holdout', [
        'qualifications' => [
            $q('Certificate in Human Resource Management, Business Administration or a related field', 'certificate', null, 'mandatory', 'Certificate or diploma in Human Resource Management, Business Administration or a related field.', 1),
            $q('Diploma in Human Resource Management, Business Administration or a related field', 'diploma', null, 'mandatory', 'Certificate or diploma in Human Resource Management, Business Administration or a related field.', 1),
        ],
        'professional_registrations' => [$r('Zambia Institute of Human Resource Management (ZIHRM)', 'mandatory', 'Registered member of the Zambia Institute of Human Resource Management (ZIHRM).')],
        'experience_requirements' => [$e(2, null, 'an HR or administrative role', 'mandatory', 'At least 2 years’ experience in an HR or administrative role.')],
    ], ['strength comes from a heading ("Essential") not the sentence', 'an "Advantage" heading follows']),

    $ad('12-chief-operating-officer.txt', 'Chief Operating Officer', 'administration', 'tune', [
        'qualifications' => [
            $q('MBA', 'masters', null, 'mandatory', 'An MBA or Master’s degree in Non-Profit Management.', 1),
            $q('Master’s degree in Non-Profit Management', 'masters', 'non-profit management', 'mandatory', 'An MBA or Master’s degree in Non-Profit Management.', 1),
        ],
        'experience_requirements' => [$e(10, 15, 'senior leadership', 'mandatory', '10–15 years of significant senior leadership experience, preferably within an NGO or comparable large organization with an annual budget of US$25 million or more.')],
    ], ['"preferably" applies to the NGO setting, not to the years: a careless reader marks the years as preferred']),

    $ad('13-intern.txt', 'Intern (x2)', 'administration', 'tune', [
        'qualifications' => [
            $q('Full Grade 12 School Certificate', 'school', null, 'mandatory', 'Full Grade 12 School Certificate or equivalent.'),
            $q('Degree in Demography, Development Studies, Economics, Public Administration or any related fields', 'bachelor', null, 'mandatory', 'A minimum of a degree in Demography, Development Studies, Economics, Public Administration or any related fields.'),
        ],
    ], ['the advert later lists "Degree Certificate(s)" and "professional qualification certificates" as documents to submit: not requirements']),

    $ad('14-business-support-assistant-wfp.txt', 'Business Support Assistant SC5 (WFP)', 'administration', 'holdout', [
        'qualifications' => [
            $q('University degree', 'bachelor', null, 'mandatory', 'University degree in Public Administration, Business Administration, International Relations, Development Studies, Information Management, Human Resource Management, Project Management or other related fields', 1),
            $q('Advanced Diploma in the above specified fields', 'diploma', null, 'mandatory', 'an Advanced Diploma in the above specified fields with five (5) years of additional work experience', 1),
        ],
        'experience_requirements' => [$e(5, null, 'the relative business stream', 'mandatory', 'Five or more years of progressively responsible work experience in the relative business stream with experience in general administrative and project management work.')],
    ], ['a long advert (over 12,000 characters) full of equal-opportunity and application text that mentions "qualified candidates" and "certificates"', 'degree OR advanced diploma with extra experience']),

    $ad('15-teaching-principal.txt', 'Teaching Principal', 'education', 'tune', [
        'qualifications' => [
            $q('Bachelor’s Degree in Education', 'bachelor', 'education', 'mandatory', 'Bachelor’s Degree in Education or a related field.'),
            $q('Recognised teaching qualification', null, null, 'mandatory', 'Recognised teaching qualification and eligibility for teacher registration.'),
        ],
        'professional_registrations' => [$r('teacher registration', 'mandatory', 'Recognised teaching qualification and eligibility for teacher registration.')],
        'experience_requirements' => [$e(7, null, 'teaching', 'mandatory', 'Minimum of 7 years’ teaching experience.')],
    ], ['an unnamed "recognised teaching qualification" and "eligibility for registration"']),

    $ad('16-primary-teacher.txt', 'Primary Teacher', 'education', 'holdout', [
        'qualifications' => [
            $q('Full Grade 12 Certificate with 5 Credits or better', 'school', null, 'mandatory', 'Full Grade 12 Certificate with 5 Credits or better'),
            $q('Diploma in Primary Education', 'diploma', 'primary education', 'mandatory', 'Diploma in Primary Education from a recognized college.'),
            $q('Bachelor’s Degree in Education or Special Education', 'bachelor', 'education or special education', 'advantage', 'A Bachelor’s Degree in Education or Special Education will be an added advantage.'),
            $q('Certificate in Education Leadership and Management', 'certificate', 'education leadership and management', 'advantage', 'Certificate/Diploma/Degree in Education Leadership and Management will be an added advantage', 1),
            $q('Diploma in Education Leadership and Management', 'diploma', 'education leadership and management', 'advantage', 'Certificate/Diploma/Degree in Education Leadership and Management will be an added advantage', 1),
            $q('Degree in Education Leadership and Management', 'bachelor', 'education leadership and management', 'advantage', 'Certificate/Diploma/Degree in Education Leadership and Management will be an added advantage', 1),
        ],
        'professional_registrations' => [$r('The Teaching Council of Zambia (TCZ)', 'mandatory', 'Qualified teacher registered with The Teaching Council of Zambia (TCZ)')],
        'experience_requirements' => [$e(2, null, 'teaching', 'mandatory', 'At least 2 years’ teaching experience')],
    ], ['the heading says "Preferred Academic and Professional Qualifications" yet the list is treated as required ("applications not in conformity will not be considered"): the OWNER must confirm', 'three levels in one slash-separated advantage line']),

    $ad('17-registrar.txt', 'Registrar (Levy Mwanawasa Medical University)', 'education', 'tune', [
        'qualifications' => [
            $q('Grade 12 School Certificate', 'school', null, 'mandatory', 'Grade 12 School Certificate or its equivalent, including English Language'),
            $q('bachelor’s degree in human resource management, Business Administration, Public Administration, Law, Education or any other relevant qualification', 'bachelor', null, 'mandatory', 'A bachelor’s degree in human resource management, Business Administration, Public Administration, Law, Education or any other relevant qualification.'),
            $q('Master’s degree in human resource management, Business Administration, Public Administration, Law, Education or any other relevant qualification', 'masters', null, 'mandatory', 'Master’s degree in human resource management, Business Administration, Public Administration, Law, Education or any other relevant qualification.'),
            $q('PhD', 'phd', null, 'advantage', 'PhD will be an added advantage'),
        ],
        'professional_registrations' => [$r('membership to a professional association or organisation', 'mandatory', 'Possess proof of membership to a professional association or organisation')],
        'experience_requirements' => [$e(8, null, 'University administration or corporate management', 'mandatory', 'Minimum of eight (8) years of work experience in University administration or corporate management')],
    ], ['a bachelor\'s AND a master\'s degree listed one after the other: both required, or a progression? (owner to confirm)', 'an unnamed professional association']),

    $ad('18-lecturers.txt', 'Lecturers (Lusaka South University College)', 'education', 'tune', [
        'qualifications' => [
            $q('Grade 12 School Certificate with 5 O’Levels', 'school', null, 'mandatory', 'Grade 12 School Certificate with 5 O’Levels (including English and Mathematics).'),
            $q('Bachelor’s Degree in Education/Curriculum Design', 'bachelor', 'education/curriculum design', 'mandatory', 'Bachelor’s Degree in Education/Curriculum Design or Equivalent.'),
            $q('Master’s Degree in Education', 'masters', 'education', 'mandatory', 'Master’s Degree in Education or Equivalent.'),
            $q('Teaching Methodology Qualification', null, null, 'mandatory', 'Teaching Methodology Qualification (Mandatory).'),
            $q('Bachelor’s Degree in appropriate field', 'bachelor', null, 'mandatory', 'Bachelor’s Degree in appropriate field (Engineering etc).'),
        ],
        'professional_registrations' => [$r('Accredited Trainer with TEVETA', 'mandatory', 'Accredited Trainer with TEVETA.')],
    ], ['TWO lecturer positions in one advert', '"or Equivalent" again', '"(Mandatory)" written in brackets', 'a vocational accreditation (TEVETA)'], true),

    $ad('19-maintenance-officer.txt', 'Maintenance Officer', 'engineering', 'tune', [
        'qualifications' => [$q('Bachelor’s degree in Mechanical Engineering, Facilities Management, or a related field', 'bachelor', null, 'mandatory', 'Bachelor’s degree in Mechanical Engineering, Facilities Management, or a related field.')],
        'professional_registrations' => [
            $r('EIZ', 'mandatory', 'Must be member of the EIZ with valid practicing certificate'),
            $r('NEBOSH', 'advantage', 'Professional safety certifications such as NEBOSH, SAMTRAC, IOSH, or local industrial safety will be an added advantage', 1),
            $r('SAMTRAC', 'advantage', 'Professional safety certifications such as NEBOSH, SAMTRAC, IOSH, or local industrial safety will be an added advantage', 1),
            $r('IOSH', 'advantage', 'Professional safety certifications such as NEBOSH, SAMTRAC, IOSH, or local industrial safety will be an added advantage', 1),
        ],
        'experience_requirements' => [
            $e(4, 5, 'industrial safety, plant maintenance, or facilities engineering', 'mandatory', 'Must have a minimum of 4 to 5 years of hands-on experience in industrial safety, plant maintenance, or facilities engineering'),
            $e(3, null, 'maintenance work with either a Mine, Engineering Firm, Construction or Oil Company', 'advantage', 'Must have a minimum of 3 years maintenance work experience with either a Mine, Engineering Firm, Construction or Oil Company will be plus.'),
        ],
    ], ['a range of years ("4 to 5")', '"Must have ... will be plus" contradicts itself (owner to confirm advantage)']),

    $ad('20-reliability-engineer.txt', 'Engineer, Reliability, Senior', 'engineering', 'holdout', [
        'qualifications' => [
            $q('Grade 12 School Certificate', 'school', null, 'mandatory', 'Grade 12 School Certificate or equivalent.'),
            $q('Bachelor’s degree in Mechanical Engineering', 'bachelor', 'mechanical engineering', 'mandatory', 'Bachelor’s degree in Mechanical Engineering (or Electro-Mechanical or Mechatronics Engineering, with relevant mobile fleet experience).'),
            $q('Certification in RCM, RCFA or reliability engineering', 'certificate', 'reliability engineering', 'advantage', 'Certification in RCM, RCFA or reliability engineering, such as CMRP, CRE or an RCM2 practitioner qualification.'),
            $q('Postgraduate qualification in Asset Management, Reliability Engineering or Engineering Management', 'postgraduate', null, 'advantage', 'Postgraduate qualification in Asset Management, Reliability Engineering or Engineering Management.'),
        ],
        'professional_registrations' => [$r('Engineering Institution of Zambia (EIZ)', 'mandatory', 'Registration with the Engineering Institution of Zambia (EIZ).')],
        'experience_requirements' => [
            $e(5, null, 'maintenance or reliability engineering in open pit mining', 'mandatory', 'At least 5 years experience in maintenance or reliability engineering in open pit mining'),
            $e(2, null, 'a reliability engineering role', 'preferred', 'At least 2 years in a reliability engineering role is preferred.'),
        ],
    ], ['strength comes from the HEADING "Advantageous" above the certification and postgraduate lines', 'the same list has both required and advantageous items']),

    $ad('21-enterprise-systems-administrator.txt', 'Administrator – Enterprise Systems (Non-Financials)', 'engineering', 'tune', [
        'qualifications' => [$q('Graduate Engineer (BSc Computer Science)', 'bachelor', 'computer science', 'mandatory', 'Graduate Engineer (BSc Computer Science) from a recognized university')],
        'professional_registrations' => [$r('ICTAZ', 'mandatory', 'ICTAZ')],
        'experience_requirements' => [$e(3, null, 'handling application systems (Telecom or related industries)', 'mandatory', '3+ years of experience in handling application systems (Telecom or related industries)')],
    ], ['a bare abbreviation ("ICTAZ") under the heading "Professional Registration"', 'a very short advert']),
];
