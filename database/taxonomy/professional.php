<?php

/**
 * Layers 4 and 5 of the qualification taxonomy.
 *
 * 'qualifications': professional qualifications and certifications you earn by examination.
 *   [key, name, awarding body, broad field key or null, [aliases]]
 * 'registrations': memberships, registrations with a regulator, and licences that a job requires you to hold.
 *   [key, name, body, kind (membership | registration | licence), [aliases]]
 *
 * An alias starting with "=" must match with exactly that capitalisation ("=CA" would not match "ca").
 */
return [
    'qualifications' => [
        // accounting, audit and finance
        ['acca', 'ACCA', 'Association of Chartered Certified Accountants', 'accounting-finance', ['ACCA', 'Chartered Certified Accountant', 'Association of Chartered Certified Accountants']],
        ['cima', 'CIMA', 'Chartered Institute of Management Accountants', 'accounting-finance', ['CIMA', 'Chartered Institute of Management Accountants', 'Chartered Management Accountant']],
        ['cpa', 'CPA', 'Certified Public Accountant', 'accounting-finance', ['CPA', 'Certified Public Accountant']],
        ['cfa', 'CFA', 'CFA Institute', 'accounting-finance', ['CFA', 'Chartered Financial Analyst']],
        ['chartered-accountant', 'Chartered Accountant', null, 'accounting-finance', ['Chartered Accountant', 'ACA', 'ICAEW']],
        ['zica-technician', 'ZICA Technician', 'Zambia Institute of Chartered Accountants', 'accounting-finance', ['ZICA Technician', 'ZICA Accounting Technician', 'ZICA AT', 'Accounting Technician ZICA']],
        ['aat', 'AAT', 'Association of Accounting Technicians', 'accounting-finance', ['AAT', 'Association of Accounting Technicians']],
        ['cia', 'CIA', 'Institute of Internal Auditors', 'accounting-finance', ['CIA', 'Certified Internal Auditor']],
        ['cisa', 'CISA', 'ISACA', 'information-technology', ['CISA', 'Certified Information Systems Auditor']],
        ['cism', 'CISM', 'ISACA', 'information-technology', ['CISM', 'Certified Information Security Manager']],
        // procurement and HR
        ['cips', 'CIPS', 'Chartered Institute of Procurement and Supply', 'supply-chain-logistics', ['CIPS', 'MCIPS', 'Chartered Institute of Procurement and Supply', 'Chartered Institute of Purchasing and Supply']],
        ['cipd', 'CIPD', 'Chartered Institute of Personnel and Development', 'business-management', ['CIPD', 'Chartered Institute of Personnel and Development']],
        // project and IT
        ['prince2', 'PRINCE2', 'AXELOS', 'business-management', ['PRINCE2', 'PRINCE 2']],
        ['pmp', 'PMP', 'Project Management Institute', 'business-management', ['PMP', 'Project Management Professional']],
        ['capm', 'CAPM', 'Project Management Institute', 'business-management', ['CAPM', 'Certified Associate in Project Management']],
        ['itil', 'ITIL', 'AXELOS', 'information-technology', ['ITIL']],
        ['csm', 'Certified ScrumMaster', 'Scrum Alliance', 'information-technology', ['CSM', 'Certified ScrumMaster', 'Certified Scrum Master', 'Scrum Master certification']],
        ['ccna', 'CCNA', 'Cisco', 'information-technology', ['CCNA', 'Cisco Certified Network Associate']],
        ['ccnp', 'CCNP', 'Cisco', 'information-technology', ['CCNP', 'Cisco Certified Network Professional']],
        ['comptia-a-plus', 'CompTIA A+', 'CompTIA', 'information-technology', ['CompTIA A+', '=A+ certification', '=A+ certified']],
        ['comptia-network-plus', 'CompTIA Network+', 'CompTIA', 'information-technology', ['CompTIA Network+', '=Network+ certification']],
        ['comptia-security-plus', 'CompTIA Security+', 'CompTIA', 'information-technology', ['CompTIA Security+', '=Security+ certification']],
        ['ceh', 'CEH', 'EC-Council', 'information-technology', ['CEH', 'Certified Ethical Hacker']],
        ['mcsa', 'Microsoft Certified Solutions Associate', 'Microsoft', 'information-technology', ['MCSA', 'Microsoft Certified Solutions Associate', 'MCSE', 'Microsoft Certified Solutions Expert']],
        // quality, safety, food
        ['six-sigma-green-belt', 'Six Sigma Green Belt', null, 'business-management', ['Six Sigma Green Belt', 'Lean Six Sigma Green Belt']],
        ['six-sigma-black-belt', 'Six Sigma Black Belt', null, 'business-management', ['Six Sigma Black Belt', 'Lean Six Sigma Black Belt']],
        ['nebosh', 'NEBOSH', 'National Examination Board in Occupational Safety and Health', null, ['NEBOSH', 'NEBOSH General Certificate', 'NEBOSH IGC']],
        ['iosh', 'IOSH', 'Institution of Occupational Safety and Health', null, ['IOSH', 'IOSH Managing Safely']],
        ['iso-9001-lead-auditor', 'ISO 9001 Lead Auditor', null, null, ['ISO 9001 Lead Auditor', 'ISO 9001 Auditor', 'Lead Auditor ISO 9001']],
        ['haccp', 'HACCP certificate', null, 'agriculture', ['HACCP certificate', 'HACCP certification', 'HACCP training']],
        ['first-aid', 'First Aid certificate', null, 'health-medicine', ['First Aid certificate', 'First Aid certification', 'First Aid training', 'Basic First Aid']],
    ],

    'registrations' => [
        ['eiz-registered-engineer', 'EIZ registered engineer', 'Engineering Institution of Zambia', 'registration', ['EIZ registered engineer', 'registered engineer EIZ', 'registered with EIZ', 'EIZ registration', 'Registered Professional Engineer']],
        ['eiz-membership', 'EIZ membership', 'Engineering Institution of Zambia', 'membership', ['EIZ membership', 'member of EIZ', 'Member of the Engineering Institution of Zambia', 'Engineering Institution of Zambia membership']],
        ['erb-registration', 'ERB registration', 'Engineers Registration Board', 'registration', ['ERB registration', 'registered with ERB', 'registered with the Engineers Registration Board', 'Engineers Registration Board registration']],
        ['hpcz-registration', 'HPCZ registration', 'Health Professions Council of Zambia', 'registration', ['HPCZ registration', 'registered with HPCZ', 'HPCZ practising license', 'Health Professions Council of Zambia registration']],
        ['mcz-registration', 'MCZ registration', 'Medical Council of Zambia', 'registration', ['MCZ registration', 'registered with MCZ', 'Medical Council of Zambia registration', 'full registration with the Medical Council of Zambia']],
        ['nmcz-registration', 'NMCZ registration', 'Nursing and Midwifery Council of Zambia', 'registration', ['NMCZ registration', 'registered with NMCZ', 'NMCZ practising license', 'Nursing and Midwifery Council of Zambia registration', 'GNC registration']],
        ['laz-practising-certificate', 'LAZ practising certificate', 'Law Association of Zambia', 'licence', ['LAZ practising certificate', 'LAZ practicing certificate', 'practising certificate', 'practicing certificate', 'Law Association of Zambia practising certificate']],
        ['zica-membership', 'ZICA membership', 'Zambia Institute of Chartered Accountants', 'membership', ['ZICA membership', 'member of ZICA', 'full member of ZICA', 'ZICA member', 'Zambia Institute of Chartered Accountants membership']],
        ['zips-membership', 'ZIPS membership', 'Zambia Institute of Purchasing and Supply', 'membership', ['ZIPS membership', 'member of ZIPS', 'Zambia Institute of Purchasing and Supply membership']],
        ['tcz-registration', 'TCZ registration', 'Teaching Council of Zambia', 'registration', ['TCZ registration', 'registered with TCZ', 'registered with the Teaching Council of Zambia', 'Teaching Council of Zambia registration']],
        ['psz-registration', 'Pharmaceutical Society of Zambia registration', 'Pharmaceutical Society of Zambia', 'registration', ['PSZ registration', 'registered with PSZ', 'Pharmaceutical Society of Zambia registration']],
        ['zia-membership', 'ZIA membership', 'Zambia Institute of Architects', 'membership', ['ZIA membership', 'member of ZIA', 'Zambia Institute of Architects membership', 'registered architect']],
        ['driving-licence', 'Valid driving licence', 'Road Transport and Safety Agency', 'licence', ['valid driving licence', 'valid driving license', 'valid driver\'s licence', 'valid drivers licence', 'driving licence', 'driving license', 'clean driving licence', 'clean driving license', 'driver\'s licence', 'drivers licence']],
    ],
];
