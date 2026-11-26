// แหล่งข้อมูลและแนวทางการใช้งาน: transcribed from the owner's public/ref/ref.docx (2026-11-26), shown on page 1
// (เกี่ยวกับระบบ). Reference reading only: nothing here feeds the engine or any calculation. Links are the ones in
// the docx; a name with no link in the docx (e.g. "Thai Weather/TMD พยากรณ์", "เช็คน้ำ", "Cell Broadcast") is plain text.

export interface RefLink {
    label: string;
    url?: string;
}
export interface RefSource {
    names: RefLink[];
    use: string;
    limit: string;
}
export interface RefCategory {
    key: string;
    label: string;
    icon: string;
    /** Tailwind classes for the icon chip. */
    color: string;
    sources: RefSource[];
}

export const REF_INTRO =
    'การจัดการภัยพิบัติที่มีประสิทธิภาพต้องอาศัยข้อมูลที่เหมาะสม ทันต่อสถานการณ์ และสอดคล้องกับบริบทพื้นที่ ผู้ใช้ต้องเข้าใจความสามารถและข้อจำกัดของข้อมูล เพื่อป้องกันความผิดพลาดในการวางแผน การตัดสินใจ และการสื่อสารกับประชาชน';

export const REF_CATEGORIES: RefCategory[] = [
    {
        key: 'weather',
        label: 'สภาพอากาศและพายุ',
        icon: 'fa-solid fa-cloud-bolt',
        color: 'bg-sky-50 text-sky-600',
        sources: [
            {
                names: [
                    { label: 'กรมอุตุนิยมวิทยา – มหาสารคาม', url: 'https://www.tmd.go.th/weather/province/maha-sarakham' },
                    { label: 'Thai Weather/TMD พยากรณ์' },
                ],
                use: 'ติดตามสภาพอากาศ ฝน พายุ และประกาศเตือนภัย',
                limit: 'พยากรณ์ระดับจังหวัด ไม่สามารถระบุเหตุการณ์รายชุมชนได้อย่างแม่นยำ',
            },
            {
                names: [{ label: 'เรดาร์ตรวจอากาศ', url: 'https://weather.tmd.go.th/' }],
                use: 'ติดตามทิศทางและการเปลี่ยนแปลงของกลุ่มฝนจากภาพต่อเนื่อง',
                limit: 'สัญญาณเรดาร์ไม่เท่ากับปริมาณฝนที่ตกถึงพื้นโดยตรง',
            },
            {
                names: [{ label: 'Windy', url: 'https://www.windy.com/' }],
                use: 'เปรียบเทียบแบบจำลองพยากรณ์ฝน ลม และอุณหภูมิ',
                limit: 'ผลพยากรณ์แต่ละแบบจำลองอาจแตกต่างกัน',
            },
            {
                names: [{ label: 'RainViewer', url: 'https://www.rainviewer.com/' }],
                use: 'ติดตามกลุ่มฝนและแนวโน้มระยะสั้น',
                limit: 'ขึ้นกับความครอบคลุมและความเป็นปัจจุบันของข้อมูลเรดาร์',
            },
        ],
    },
    {
        key: 'water',
        label: 'น้ำท่วมและภัยแล้ง',
        icon: 'fa-solid fa-house-flood-water',
        color: 'bg-blue-50 text-blue-600',
        sources: [
            {
                names: [
                    { label: 'ศูนย์ข้อมูลน้ำจังหวัดมหาสารคาม', url: 'https://mahasarakham.thaiwater.net/dashboard' },
                    { label: 'ThaiWater', url: 'https://www.thaiwater.net/' },
                ],
                use: 'ติดตามฝน ระดับน้ำ น้ำต้นทุน และแนวโน้มสถานการณ์',
                limit: 'ตรวจสอบตำแหน่งสถานี หน่วยวัด ระดับอ้างอิง และเวลาที่ปรับปรุงล่าสุด',
            },
            {
                names: [{ label: 'กรมชลประทาน', url: 'https://telerid.rid.go.th/' }],
                use: 'ติดตามระดับน้ำและการบริหารจัดการน้ำในลุ่มน้ำ',
                limit: 'ข้อมูลแต่ละสถานีมีความสัมพันธ์กับพื้นที่แตกต่างกัน',
            },
            {
                names: [{ label: 'กฟผ. – แผนผังการไหลของน้ำ', url: 'https://watertele2.egat.co.th/riverflow' }],
                use: 'ติดตามน้ำต้นน้ำและการระบายน้ำจากเขื่อน',
                limit: 'ไม่สามารถระบุเวลาที่น้ำจะมาถึงเมืองจากระยะทางเพียงอย่างเดียว',
            },
            {
                names: [{ label: 'S2S – สสน.', url: 'https://s2s.hii.or.th/' }],
                use: 'วางแผนล่วงหน้าหลายสัปดาห์ถึงตามฤดูกาล',
                limit: 'ใช้ประเมินแนวโน้ม ไม่ใช้ระบุวันเกิดภัยที่แน่นอน',
            },
            {
                names: [{ label: 'GISTDA Disaster', url: 'https://disaster.gistda.or.th/' }, { label: 'เช็คน้ำ' }],
                use: 'วิเคราะห์พื้นที่น้ำท่วมและพื้นที่เสี่ยง',
                limit: 'ต้องแยกข้อมูลพื้นที่น้ำท่วมที่ตรวจพบออกจากพื้นที่คาดการณ์หรือพื้นที่เสี่ยง',
            },
            {
                names: [{ label: 'Google Flood Hub', url: 'https://sites.research.google/floods/l/0/0/3' }],
                use: 'ติดตามแนวโน้มน้ำท่วมจากการพยากรณ์',
                limit: 'ต้องตรวจสอบความครอบคลุมและความแม่นยำเฉพาะพื้นที่',
            },
            {
                names: [{ label: 'FastFlood', url: 'https://fastflood.org/' }],
                use: 'จำลองสถานการณ์น้ำท่วมและวางแผนลดความเสี่ยง',
                limit: 'ผลจำลองขึ้นกับข้อมูลนำเข้าและสมมติฐาน ไม่ใช่เหตุการณ์จริง',
            },
            {
                names: [{ label: 'เช็คแล้ง – GISTDA', url: 'https://cropsdrought.gistda.or.th/' }],
                use: 'ประเมินความเสี่ยงภัยแล้งทางการเกษตร',
                limit: 'ไม่ใช่ตัวชี้วัดการขาดแคลนน้ำประปาโดยตรง',
            },
        ],
    },
    {
        key: 'air',
        label: 'คุณภาพอากาศและการเผา',
        icon: 'fa-solid fa-smog',
        color: 'bg-slate-100 text-slate-600',
        sources: [
            {
                names: [{ label: 'Air4Thai', url: 'https://air4thai.pcd.go.th/' }],
                use: 'ติดตามค่าฝุ่นจากสถานีตรวจวัด ใช้ค่าเฉลี่ย 24 ชั่วโมงเปรียบเทียบมาตรฐาน',
                limit: 'ไม่ใช่ค่าตรวจวัดของทุกชุมชน และไม่ใช้ค่ารายชั่วโมงเปรียบเทียบมาตรฐาน 24 ชั่วโมง',
            },
            {
                names: [{ label: 'GISTDA PM2.5 / เช็คฝุ่น', url: 'https://pm25.gistda.or.th/' }],
                use: 'วิเคราะห์การกระจายตัวของฝุ่นและคัดกรองพื้นที่เฝ้าระวัง',
                limit: 'เป็นค่าประมาณเชิงพื้นที่ ไม่ใช่ค่าตรวจวัดจริงทุกตำแหน่ง',
            },
            {
                names: [{ label: 'Ventilation Rate – TMD', url: 'https://ozone.tmd.go.th/wi_daily.html' }],
                use: 'เตรียมมาตรการรับมือการสะสมของฝุ่นล่วงหน้า',
                limit: 'บ่งชี้ศักยภาพการระบายมลพิษ ไม่ใช่การพยากรณ์ความเข้มข้น PM2.5 โดยตรง',
            },
            {
                names: [{ label: 'Hotspot – GISTDA', url: 'https://disaster.gistda.or.th/' }],
                use: 'ชี้เป้าพื้นที่ที่อาจเกิดไฟหรือการเผา',
                limit: 'ไม่สามารถตรวจพบการเผาทุกครั้ง และไม่ใช่หลักฐานยืนยันผู้กระทำผิด',
            },
            {
                names: [{ label: 'ตามรอยเผา – สสน.', url: 'https://tamroypao.hii.or.th/map.jsp' }],
                use: 'วิเคราะห์พื้นที่เผาไหม้ย้อนหลังและประเมินมาตรการลดการเผา',
                limit: 'ไม่ใช่ระบบแจ้งเหตุไฟไหม้ทันที',
            },
        ],
    },
    {
        key: 'alert',
        label: 'สุขภาพและการแจ้งเตือนภัย',
        icon: 'fa-solid fa-bell',
        color: 'bg-rose-50 text-rose-600',
        sources: [
            {
                names: [{ label: 'LifeDee', url: 'https://lifedee.gistda.or.th/' }],
                use: 'ติดตามฝุ่น ดัชนีความร้อน และคำแนะนำด้านสุขภาพ',
                limit: 'ไม่ใช่เครื่องมือวินิจฉัยโรคหรือประเมินการสัมผัสเฉพาะบุคคล',
            },
            {
                names: [{ label: 'Thai Disaster Alert – ปภ.', url: 'https://tdaweb.disaster.go.th/tda/AlertHome' }, { label: 'Cell Broadcast' }],
                use: 'ประกาศเตือนภัยและเตรียมปฏิบัติตามแผนเผชิญเหตุ',
                limit: 'ตรวจสอบพื้นที่ เวลา และคำแนะนำของประกาศ',
            },
            {
                names: [{ label: 'ระบบเฝ้าระวังแผ่นดินไหว – TMD', url: 'https://earthquake.tmd.go.th/' }],
                use: 'ตรวจสอบเหตุแผ่นดินไหวและประกอบการประเมินผลกระทบ',
                limit: 'ไม่สามารถพยากรณ์วัน เวลา และตำแหน่งเกิดแผ่นดินไหวได้อย่างแม่นยำ',
            },
        ],
    },
];

export const REF_HORIZONS = [
    { when: 'ปัจจุบัน–ไม่กี่ชั่วโมง', use: 'ใช้ข้อมูลตรวจวัด เรดาร์ และรายงานภาคสนาม เพื่อเฝ้าระวังและตอบโต้เหตุ', icon: 'fa-solid fa-bolt' },
    { when: 'ล่วงหน้า 1–3 วัน', use: 'ใช้ข้อมูลพยากรณ์เพื่อเตรียมบุคลากร อุปกรณ์ และมาตรการป้องกัน', icon: 'fa-solid fa-calendar-day' },
    { when: 'ล่วงหน้า 4–10 วัน', use: 'ใช้ติดตามแนวโน้ม วางแผนเบื้องต้น และตรวจสอบผลพยากรณ์รอบใหม่', icon: 'fa-solid fa-calendar-week' },
    { when: 'หลายสัปดาห์–ตามฤดูกาล', use: 'วางแผนทรัพยากรและเตรียมความพร้อม', icon: 'fa-solid fa-calendar' },
    { when: 'ระยะยาว', use: 'ใช้ข้อมูลย้อนหลัง แบบจำลอง และภาพฉายภูมิอากาศ เพื่อวางแผนพัฒนาเมืองและลดความเสี่ยง', icon: 'fa-solid fa-city' },
];
export const REF_HORIZON_NOTE = 'ช่วงเวลาดังกล่าวเป็นแนวทางการใช้งาน ไม่ใช่การรับรองความแม่นยำของการพยากรณ์';

export const REF_SKILLS = [
    { title: 'รู้จักข้อมูล', text: 'แยกข้อมูลตรวจวัด ประมาณค่า พยากรณ์ และจำลองออกจากกัน', icon: 'fa-solid fa-layer-group' },
    { title: 'อ่านข้อมูลเป็น', text: 'เข้าใจหน่วยวัด สี สัญลักษณ์ กราฟ และช่วงเวลาเฉลี่ย', icon: 'fa-solid fa-chart-line' },
    { title: 'ตรวจสอบข้อมูลได้', text: 'ตรวจสอบแหล่งที่มา เวลา ความละเอียด และความครอบคลุม', icon: 'fa-solid fa-magnifying-glass' },
    { title: 'เข้าใจข้อจำกัด', text: 'รู้ว่าข้อมูลสามารถบอกอะไรได้และไม่ได้', icon: 'fa-solid fa-scale-balanced' },
    {
        title: 'เชื่อมโยงกับพื้นที่',
        text: 'พิจารณาภัย ผู้ที่อาจได้รับผลกระทบ ความเปราะบาง และศักยภาพการรับมือ (HEVC)',
        icon: 'fa-solid fa-map-location-dot',
    },
    {
        title: 'เรียนรู้จากการใช้งาน',
        text: 'ฝึกใช้ข้อมูลจริง เปรียบเทียบกับสถานการณ์ในพื้นที่ และทบทวนผลการตัดสินใจ',
        icon: 'fa-solid fa-graduation-cap',
    },
];

/** `urgent` = the life-threat rule, shown highlighted. */
export const REF_CAUTIONS: { text: string; urgent?: boolean }[] = [
    { text: 'ใช้ข้อมูลให้ตรงกับวัตถุประสงค์และขอบเขตความสามารถของแต่ละระบบ' },
    { text: 'ตรวจสอบเวลา หน่วยวัด ตำแหน่ง และคุณภาพข้อมูลก่อนนำไปใช้' },
    { text: 'ความละเอียดของข้อมูลไม่เท่ากับความแม่นยำ' },
    { text: 'ข้อมูลจากหลายแอปอาจมีต้นทางเดียวกัน' },
    { text: 'ผลพยากรณ์และผลจำลองไม่ใช่เหตุการณ์ที่จะเกิดขึ้นอย่างแน่นอน' },
    { text: 'เมื่อข้อมูลขัดแย้งกัน ให้ตรวจสอบต้นทางและสถานการณ์จริงเพิ่มเติม' },
    { text: 'ไม่มีข้อมูลหรือไม่มีการแจ้งเตือน ไม่ได้หมายความว่าไม่มีภัย' },
    { text: 'การตัดสินใจที่มีผลกระทบสูงต้องพิจารณาข้อมูลประกอบ แผนปฏิบัติงาน และอำนาจหน้าที่ของหน่วยงาน' },
    { text: 'หากเกิดภัยคุกคามต่อชีวิต ให้ดำเนินการตามแผนเผชิญเหตุทันที โดยไม่รอข้อมูลครบทุกแหล่ง', urgent: true },
    { text: 'บันทึกข้อมูลและผลการดำเนินงาน เพื่อนำไปปรับปรุงมาตรการและวางแผนพัฒนาพื้นที่ในระยะยาว' },
];
